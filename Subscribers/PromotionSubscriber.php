<?php

namespace WebPromotionExtension\Subscribers;

use Doctrine\DBAL\Connection;
use Enlight\Event\SubscriberInterface;
use Enlight_Components_Session_Namespace as Session;
use Enlight_Template_Manager as TemplateManager;
use Shopware_Components_Config as Config;
use Shopware\Bundle\StoreFrontBundle\Service\ContextServiceInterface;
use SwagPromotion\Components\Promotion\CurrencyConverter;
use SwagPromotion\Components\Promotion\Selector\Selector;
use SwagPromotion\Components\Services\ProductServiceInterface;
use SwagPromotion\Struct\AppliedPromotions;
use SwagPromotion\Struct\Promotion;
use SwagPromotion\Struct\Promotion as PromotionStruct;

class PromotionSubscriber implements SubscriberInterface
{
    /**
     * Prevent promotions from being inserted recursively.
     *
     * @var bool
     */
    private $ignoreBasketRefresh;

    /**
     * @var string
     */
    private $lastBasketHash;

    public function __construct(
        private Connection $connection,
        private TemplateManager $templateManager,
        private Config $config,
        private Session $session,
        private ContextServiceInterface $contextService,
        private ProductServiceInterface $productService,
        private Selector $promotionSelector,
        private \Enlight_Controller_Front $front,
        private CurrencyConverter $currencyConverter,
        private \Shopware_Components_Snippet_Manager $snippetManager
    )
    {
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            'sBasket::sGetBasket::before' => 'beforeGetBasket',
            'sBasket::sGetBasket::after' => 'afterGetBasket',
            "sBasket::sAddVoucher::before" => 'onAddVoucherStart',
            "sBasket::sAddVoucher::after" => 'onAfterAddVoucher',
            'Shopware_Modules_Order_SaveOrder_OrderCreated' => 'onAfterOrderCreated',
        ];
    }

    public function onAddVoucherStart(\Enlight_Event_EventArgs $args)
    {
        $params = $this->front->Request()->getParams();
        $voucherCode = $params['sVoucher'];
        $this->session->offsetUnset('promotionsForVoucherData');
        $promotionsForVoucher = $this->getPromotionsForVoucher($voucherCode);
        if($promotionsForVoucher) {
            $this->session->offsetSet('promotionsForVoucherData', $promotionsForVoucher);
        }
    }

    public function onAfterAddVoucher(\Enlight_Event_EventArgs $args)
    {
        $keepPromotionData = $this->session->offsetExists('promotionVouchers') ? $this->session->offsetGet('promotionVouchers') : [];
        $voucherArticles = $this->hasBasketPromotionArticle();
        foreach ($voucherArticles AS $key => $voucherArticle) {
            if(empty($key)) {
                continue;
            }
            $sql = 'DELETE FROM s_order_basket
            WHERE id = :basketId AND sessionID = :sessionId AND modus = :modus';

            $this->connection->executeQuery(
                $sql,
                [
                    'sessionId' => $this->session->get('sessionId'),
                    'basketId' => $voucherArticle['basketId'],
                    'modus' => $voucherArticle['modus']
                ]
            );
        }

        if ((count($voucherArticles) > 1) || ($keepPromotionData && (count($voucherArticles) >= 1))) {
            $sErrorMessages[] = $this->snippetManager->getNamespace('frontend/basket/internalMessages')->get(
                'VoucherFailureOnlyOnes',
                'Only one voucher can be processed in order'
            );

            return ['sErrorFlag' => true, 'sErrorMessages' => $sErrorMessages];
        }
    }

    public function onAfterOrderCreated(\Enlight_Event_EventArgs $args)
    {
        $articleDetails = $args->get('details');
        $orderId = $args->get('orderId');

        $keepPromotionData = $this->session->offsetExists('promotionVouchers') ? $this->session->offsetGet('promotionVouchers') : [];
        sort($keepPromotionData);
        if(empty($keepPromotionData[0])) {
            return;
        }

        foreach ($articleDetails AS $articleDetail) {
            if($articleDetail['__s_order_basket_attributes_swag_promotion_id'] == $keepPromotionData[0]['promotionId']) {
                $this->connection->executeUpdate('UPDATE s_emarketing_voucher_codes SET cashed = 1, userID= ? WHERE code = ?', [intval($articleDetail['userID']), $keepPromotionData[0]['code']]);
                $this->connection->executeQuery(
                    "INSERT INTO s_plugin_promotion_customer_count (promotion_id, customer_id, order_id)  VALUES(?, ?, ?)",
                    [$keepPromotionData[0]['promotionId'], intval($articleDetail['userID']), $orderId]);
                $this->session->offsetUnset('promotionVouchers');
            }
        }
    }

    /**
     * Remove existing promotions
     */
    public function beforeGetBasket()
    {
        if ($this->ignoreBasketRefresh || !$this->basketRefreshNeeded()) {
            return;
        }

        $checkPromotionArticle = $this->getPromotionBasketArticle();

        if(empty($checkPromotionArticle)) {
            $this->resetPromotionAttributes();
            $this->resetPromotionPositions();
        }
    }

    /**
     * Check and add promotions
     */
    public function afterGetBasket(\Enlight_Hook_HookArgs $args)
    {
        if ($this->ignoreBasketRefresh || !$this->basketRefreshNeeded()) {
            return;
        }

        $this->ignoreBasketRefresh = true;
        $checkPromotionArticle = $this->getPromotionBasketArticle();

        $isSetSessionData = 0;
        if(empty($checkPromotionArticle)) {
            $this->resetPromotionAttributes();
            $this->resetPromotionPositions();
            $isSetSessionData = 1;
            $this->session->offsetUnset('getPromotionArticleData');
        }

        if(!$isSetSessionData) {
            $this->session->offsetSet('getPromotionArticleData', $checkPromotionArticle);
        }

        $this->setBasketInsertQuery();
        $appliedPromotions = $this->promotionSelector->apply(
            $args->getReturn(),
            $this->contextService->getShopContext()->getCurrentCustomerGroup()->getId(),
            $this->session->get('sUserId'),
            $this->contextService->getShopContext()->getShop()->getId(),
            $this->getVoucherIdsFromSession()
        );
        $promotionArticleData = $this->session->offsetExists('getPromotionArticleData') ? $this->session->offsetGet('getPromotionArticleData') : [];

        if($promotionArticleData) {
            $sql = "UPDATE s_order_basket_attributes AS oba
                LEFT JOIN s_order_basket AS ob ON ob.id = oba.basketID
                SET oba.swag_is_free_good_by_promotion_id = :freeGoodPromotionId
                WHERE ob.sessionID = :sessionId AND oba.basketID = :basketId
                ";
            $this->connection->executeQuery(
                $sql,
                [
                    'freeGoodPromotionId' => $promotionArticleData[0]['swag_is_free_good_by_promotion_id'],
                    'sessionId' => $this->session->get('sessionId'),
                    'basketId' => $promotionArticleData[0]['basketId'],
                ]
            );

            foreach ($appliedPromotions->basket['content'] AS &$content) {
                if($content['id'] == $promotionArticleData[0]['basketId']) {
                    $content['isFreeGoodByPromotionId'] = $promotionArticleData[0]['swag_is_free_good_by_promotion_id'];
                }
            }
        }

        $basket = $this->populateBasketWithPromotionAttributes($appliedPromotions);
        if(count($basket['content'])) {
            $this->session->offsetUnset('promotionsForVoucherData');
        }

        $args->setReturn($basket);

        if (\count($basket['content']) === 0) {
            $this->removePremiumShippingCosts();
        } else {
            $this->session->offsetSet('appliedPromotions', $appliedPromotions->promotionIds);
        }

        $this->templateManager->assign('availablePromotions', $this->session->get('appliedPromotions'));
        $this->templateManager->assign('promotionsUsedTooOften', $appliedPromotions->promotionsUsedTooOften);
        $this->templateManager->assign('promotionsDoNotMatch', $appliedPromotions->promotionsDoNotMatch);

        $freeGoods = [];
        $freeGoodsHasQuantitySelect = false;
        $promotionsForVoucher = $this->session->offsetExists('promotionsForVoucherData') ? $this->session->offsetGet('promotionsForVoucherData') : [];
        if($promotionsForVoucher) {
            $freeGoodsArticles[$promotionsForVoucher['promotionId']][] = $promotionsForVoucher['articleID'];
            $appliedPromotions->freeGoodsArticlesIds = $freeGoodsArticles;
            $appliedPromotions->freeGoodsBundleMaxQuantity[$promotionsForVoucher['promotionId']] = $promotionsForVoucher['mode'];
        }

        foreach ($appliedPromotions->freeGoodsArticlesIds as $promotionId => $freeGoodsArticles) {
            $articlesData = $this->productService->getFreeGoods($freeGoodsArticles, $promotionId);
            foreach ($articlesData as &$freeGood) {
                $freeGood['maxQuantity'] = $appliedPromotions->freeGoodsBundleMaxQuantity[$promotionId];
                if ($freeGood['maxQuantity']) {
                    $freeGoodsHasQuantitySelect = true;
                }
            }

            $freeGoods = $this->mergeFreeGoods($appliedPromotions, $promotionId, $freeGoods, $articlesData);
        }

        $this->templateManager->assign('freeGoods', $freeGoods);
        $this->templateManager->assign('freeGoodsHasQuantitySelect', $freeGoodsHasQuantitySelect);

        $this->lastBasketHash = $this->getBasketHash();

        $this->ignoreBasketRefresh = false;
    }


    /**
     * @param AppliedPromotions
     *
     * @return array
     */
    private function populateBasketWithPromotionAttributes(AppliedPromotions $appliedPromotions)
    {
        $basket = $this->getBasket($appliedPromotions);

        $ids = array_column($basket['content'], 'id');
        if (!$ids) {
            return $basket;
        }

        $questionMarks = implode(', ', array_fill(0, \count($ids), '?'));
        $sql = 'SELECT basketId, swag_promotion_id
                FROM s_order_basket_attributes
                WHERE basketId IN (' . $questionMarks . ')';
        $result = $this->connection->executeQuery($sql, $ids)->fetchAll(\PDO::FETCH_UNIQUE);

        $promotionsFromVouchers = array_column(
            $this->getVoucherFromSession(),
            'voucherId',
            'promotionId'
        );

        foreach ($basket['content'] as $key => &$row) {
            $promotionId = $result[$row['id']]['swag_promotion_id'];
            if ($row['isFreeGoodByPromotionId']) {
                $appliedPromotionId = array_shift(unserialize($row['isFreeGoodByPromotionId']));
                $row['freeGoodsBundleBadge'] = $appliedPromotions->freeGoodsBadges[$appliedPromotionId];
            }

            $promotionVoucherId = \array_key_exists(
                $promotionId,
                $promotionsFromVouchers
            ) ? $promotionsFromVouchers[$promotionId] : 0;

            if ($promotionVoucherId === 0) {
                continue;
            }

            $promotionVoucherIds = $this->templateManager->getTemplateVars('promotionVoucherIds');
            if (empty($promotionVoucherIds)) {
                $promotionVoucherIds = [$row['id'] => $promotionVoucherId];
            } else {
                $promotionVoucherIds[$row['id']] = $promotionVoucherId;
            }
            $this->templateManager->assign('promotionVoucherIds', $promotionVoucherIds);
        }

        return $basket;
    }

    /**
     * Helper function to remove premium shipping costs when the basket is empty after deleting a promotion
     */
    private function removePremiumShippingCosts()
    {
        $surcharge_ordernumber = $this->config->get('sPAYMENTSURCHARGEABSOLUTENUMBER', 'PAYMENTSURCHARGEABSOLUTENUMBER');
        $discount_basket_ordernumber = $this->config->get('sDISCOUNTNUMBER', 'DISCOUNT');
        $discount_ordernumber = $this->config->get('sSHIPPINGDISCOUNTNUMBER', 'SHIPPINGDISCOUNT');
        $percent_ordernumber = $this->config->get('sPAYMENTSURCHARGENUMBER', 'PAYMENTSURCHARGE');

        $sql = 'DELETE FROM s_order_basket
                WHERE sessionID = :sessionId
                AND modus IN (3,4)
                AND ordernumber IN (:numbers)';

        $numbers = implode(',', [
            $surcharge_ordernumber,
            $discount_ordernumber,
            $percent_ordernumber,
            $discount_basket_ordernumber,
        ]);

        $this->connection->executeQuery(
            $sql,
            [
                'sessionId' => $this->session->get('sessionId'),
                'numbers' => $numbers,
            ]
        );
    }


    private function mergeFreeGoods(AppliedPromotions $appliedPromotions, int $promotionId, array $freeGoods, array $articlesData): array
    {
        if ($appliedPromotions->promotionTypes[$promotionId] === PromotionStruct::TYPE_PRODUCT_FREEGOODSBUNDLE
            && $appliedPromotions->freeGoodsBundleMaxQuantity[$promotionId]) {
            $freeGoods = array_merge($freeGoods, $this->updateFreeGoodMaxQuantity($articlesData, $appliedPromotions->freeGoodsBundleMaxQuantity[$promotionId]));
        }

        if ($appliedPromotions->promotionTypes[$promotionId] === PromotionStruct::TYPE_PRODUCT_FREEGOODS) {
            $freeGoods = array_merge($freeGoods, $articlesData);
        }

        return $freeGoods;
    }


    private function updateFreeGoodMaxQuantity(array $freeGoods, int $freeGoodsBundleMaxQuantity): array
    {
        foreach ($freeGoods as &$freeGood) {
            $max = $freeGoodsBundleMaxQuantity;
            if ($freeGood['laststock'] && $freeGood['instock'] < $max) {
                $max = $freeGood['instock'];
            }

            $freeGood['maxQuantity'] = $max;
        }

        return $freeGoods;
    }

    /**
     * Check if the basket needs to be recalculated in regards to promotion.
     * This is the case, if the basket is not known, yet (lastBasketHash) or the basket hash changed
     *
     * @return bool
     */
    private function basketRefreshNeeded()
    {
        return !$this->lastBasketHash || $this->lastBasketHash !== $this->getBasketHash();
    }

    /**
     * Calculates a md5 hash over the basket.
     *
     * @return string
     */
    private function getBasketHash()
    {
        $sql = 'SELECT *
                FROM s_order_basket sob
                LEFT JOIN s_order_basket_attributes soba
                  ON soba.basketID = sob.id
                WHERE sob.sessionID = :sessionId';

        $data['basket'] = $this->connection->fetchAll($sql, ['sessionId' => $this->session->get('sessionId')]);
        // subshop switches etc.
        $data['shop'] = $this->contextService->getShopContext();

        return md5(serialize($data));
    }

    private function resetPromotionPositions()
    {
        $sql = 'DELETE oba, ob

             FROM s_order_basket ob

             LEFT JOIN s_order_basket_attributes oba
             ON ob.id = oba.basketID

             WHERE ob.sessionID = :sessionId
             AND oba.swag_promotion_id > 0';

        $this->connection->executeQuery(
            $sql,
            [
                'sessionId' => $this->session->get('sessionId'),
            ]
        );
    }

    private function getPromotionBasketArticle()
    {
        $sDatas = $this->getBasketData();
        foreach ($sDatas AS $sData) {
            if(empty($sData['swag_is_free_good_by_promotion_id'])) {
                return false;
            }
        }

        return $sDatas;
    }

    private function resetPromotionAttributes()
    {
        $sql = <<<SQL
UPDATE s_order_basket_attributes
LEFT JOIN s_order_basket ON s_order_basket.id = s_order_basket_attributes.basketID
SET swag_promotion_item_discount = 0, swag_promotion_direct_item_discount = 0, swag_promotion_direct_promotions = NULL
WHERE s_order_basket.sessionID = :sessionId
SQL;
        $this->connection->executeQuery(
            $sql,
            [
                'sessionId' => $this->session->get('sessionId'),
            ]
        );
    }

    private function getVoucherIdsFromSession(): array
    {
        $vouchers = $this->getVoucherFromSession();

        return array_keys($vouchers);
    }

    private function getVoucherFromSession(): array
    {
        $promotionVouchers = $this->session->offsetGet('promotionVouchers');
        if ($promotionVouchers === null) {
            return [];
        }

        return $promotionVouchers;
    }

    private function getBasket(AppliedPromotions $appliedPromotions): array
    {
        $basket = $appliedPromotions->basket;

        if (isset($basket['content'])) {
            return $basket;
        }

        return ['content' => []];
    }

    /**
     * Get promotions and vouchers that might belong together
     */
    private function getPromotionsForVoucher(string $code): array
    {
        // the union is used as we want to look up vouchers by
        // * general code (mode=0) as well as by
        // * individual code (mode=1)
        $sql = 'SELECT promotions.id AS promotionId, promotions.name, promotions.number, promotions.shipping_free, freeGoods.articleID,  vouchers.id AS voucherId, vouchers.vouchercode AS code, 0 AS mode
                FROM s_emarketing_vouchers vouchers
                
                INNER JOIN s_plugin_promotion promotions ON promotions.voucher_id = vouchers.id
                LEFT JOIN s_plugin_promotion_free_goods AS freeGoods ON freeGoods.promotionID = promotions.id

                WHERE vouchercode = :code AND vouchers.modus = 0

                UNION ALL
                SELECT promotions.id AS promotionId, promotions.name, promotions.number, promotions.shipping_free, freeGoods.articleID, voucherID AS voucherId, codes.code, 1 AS mode
                FROM s_emarketing_voucher_codes codes
                INNER JOIN s_plugin_promotion promotions ON promotions.voucher_id = codes.voucherID
                LEFT JOIN s_plugin_promotion_free_goods AS freeGoods ON freeGoods.promotionID = promotions.id

                WHERE code = :code
                  AND cashed = 0';

        $result = $this->connection->fetchAll($sql, ['code' => $code]);

        $vouchers = [];
        foreach ($result as &$voucher) {
            $voucher['number'] = !empty($voucher['number']) ? $voucher['number'] : 'prom-' . $voucher['promotionId'];
            $vouchers = $voucher;
        }

        return $vouchers;
    }

    /**
     * @param float  $discountGross
     * @param float  $discountNet
     * @param string $taxRate
     *
     * @return \Doctrine\DBAL\Query\QueryBuilder
     */
    private function setBasketInsertQuery()
    {
        $basketData = $this->getBasketData();
        $hasPromotionArticle = $this->hasBasketPromotionArticle();
        $promotion = $this->session->offsetGet('promotionsForVoucherData');
        if(count($basketData) > 1 || $hasPromotionArticle || empty($basketData[0]['swag_is_free_good_by_promotion_id']) || empty($promotion)) {
            return;
        }

        $userId = $this->session->get('sUserId') ?: 0;
        $discountName = $promotion['name'];

        $basketQuery = $this->connection->createQueryBuilder()
            ->insert('s_order_basket')
            ->setValue('sessionID', ':sessionId')
            ->setValue('userID', ':userID')
            ->setValue('articlename', ':articlename')
            ->setValue('ordernumber', ':ordernumber')
            ->setValue('price', ':price')
            ->setValue('netprice', ':netprice')
            ->setValue('tax_rate', ':tax_rate')
            ->setValue('currencyfactor', ':currencyfactor')
            ->setValue('shippingfree', ':shippingfree')
            ->setValue('articleID', 0)
            ->setValue('quantity', 1)
            ->setValue('modus', 4)
            ->setParameters([
                'sessionId' => $this->session->get('sessionId'),
                'userID' => $userId,
                'articlename' => $discountName,
                'ordernumber' => $promotion['number'],
                'price' => -$basketData[0]['price'],
                'netprice' => -$basketData[0]['netprice'],
                'tax_rate' => $basketData[0]['tax_rate'],
                'currencyfactor' => $this->currencyConverter->getFactor(),
                'shippingfree' => (int) $promotion['shippingFree'],
            ]);
        $basketQuery->execute();

        $lastBasketId = $this->connection->lastInsertId('s_order_basket');

        $basketQuery = $this->connection->createQueryBuilder()
            ->insert('s_order_basket_attributes')
            ->setValue('swag_promotion_id', ':promotionId')
            ->setValue('basketID', ':basketID')
            ->setParameter('promotionId', $promotion['promotionId'])
            ->setParameter('basketID', $lastBasketId);
        $basketQuery->execute();

        return $lastBasketId;
    }

    private function getBasketData() {
        $sql = 'SELECT ob.id AS basketId, ob.price, ob.netprice, ob.tax_rate, oba.swag_is_free_good_by_promotion_id
              FROM s_order_basket ob

             LEFT JOIN s_order_basket_attributes oba ON ob.id = oba.basketID

             WHERE ob.sessionID = :sessionId
             AND ob.modus = 0';

        return $this->connection->executeQuery($sql, ['sessionId' => $this->session->get('sessionId'),])->fetchAll();
    }

    private function hasBasketPromotionArticle() {
        $sql = 'SELECT ob.id AS basketId, ob.modus
              FROM s_order_basket ob

             LEFT JOIN s_order_basket_attributes oba ON ob.id = oba.basketID

             WHERE ob.sessionID = :sessionId
             AND ob.modus IN (2, 4)';

        return $this->connection->executeQuery($sql, ['sessionId' => $this->session->get('sessionId'),])->fetchAll();
    }
}