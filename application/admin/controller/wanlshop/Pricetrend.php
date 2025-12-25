<?php

namespace app\admin\controller\wanlshop;

use app\common\controller\Backend;
use think\Config;
use think\Db;

/**
 * 价格趋势统计
 *
 * @icon fa fa-line-chart
 * @remark 统计平台所有店铺SKU的历史价格变动，对比平台价格与商家均价
 */
class Pricetrend extends Backend
{
    protected $noNeedRight = ['getCategoryList', 'getSpecList', 'getTrendData', 'getOverviewData', 'getCityList', 'getMarketPriceOverview'];

    /**
     * 主页面
     */
    public function index()
    {
        // 获取分类列表（只获取有上架商品的分类）
        $prefix = Config::get('database.prefix');
        $categories = Db::name('wanlshop_category')
            ->alias('c')
            ->join("{$prefix}wanlshop_goods g", "g.category_id = c.id AND g.deletetime IS NULL AND g.status = 'normal'", 'INNER')
            ->where('c.type', 'goods')
            ->where('c.status', 'normal')
            ->group('c.id')
            ->field('c.id, c.name')
            ->order('c.weigh DESC, c.id ASC')
            ->select();

        $this->view->assign('categories', $categories);

        // 默认选择第一个分类
        $defaultCategoryId = $categories[0]['id'] ?? 0;
        $this->assignconfig('defaultCategoryId', $defaultCategoryId);

        return $this->view->fetch();
    }

    public function getOverviewData()
    {
        $startDate = $this->request->param('start_date', '');
        $endDate = $this->request->param('end_date', '');
        $cityName = $this->request->param('city_name', '');

        // 默认显示最近30天
        if (!$startDate) {
            $startDate = date('Y-m-d', strtotime('-30 days'));
        }
        if (!$endDate) {
            $endDate = date('Y-m-d');
        }

        // 转换为时间戳
        $startTime = strtotime($startDate . ' 00:00:00');
        $endTime = strtotime($endDate . ' 23:59:59');

        $prefix = Config::get('database.prefix');

        // 生成日期序列
        $dates = [];
        $current = strtotime($startDate);
        $end = strtotime($endDate);
        while ($current <= $end) {
            $dates[] = date('Y-m-d', $current);
            $current += 86400;
        }

        // 1. 获取各分类的当前在售价格统计（只统计上架商品）
        $categoryStatsQuery = Db::name('wanlshop_goods_sku')
            ->alias('sku')
            ->join("{$prefix}wanlshop_goods g", "sku.goods_id = g.id", 'INNER')
            ->join("{$prefix}wanlshop_category c", "c.id = g.category_id", 'INNER')
            ->where('g.deletetime', null)
            ->where('g.status', 'normal')  // 只统计上架商品
            ->where('sku.deletetime', null)
            ->where('sku.state', '0')
            ->where('sku.price', '>', 0);

        if ($cityName !== '') {
            $categoryStatsQuery->where('g.region_city_name', $cityName);
        }

        $categoryStats = $categoryStatsQuery
            ->group('g.category_id')
            ->field([
                'g.category_id',
                'c.name as category_name',
                'AVG(CASE WHEN g.shop_id = 1 THEN sku.price ELSE NULL END) as platform_avg',
                'AVG(CASE WHEN g.shop_id != 1 THEN sku.price ELSE NULL END) as merchant_avg',
                'MIN(CASE WHEN g.shop_id != 1 THEN sku.price ELSE NULL END) as merchant_min',
                'MAX(CASE WHEN g.shop_id != 1 THEN sku.price ELSE NULL END) as merchant_max',
                'COUNT(DISTINCT CASE WHEN g.shop_id = 1 THEN g.id ELSE NULL END) as platform_goods_count',
                'COUNT(DISTINCT CASE WHEN g.shop_id != 1 THEN g.id ELSE NULL END) as merchant_goods_count',
                'COUNT(DISTINCT CASE WHEN g.shop_id != 1 THEN g.shop_id ELSE NULL END) as merchant_shop_count',
                'COUNT(sku.id) as sku_count'
            ])
            ->order('c.weigh DESC')
            ->select();

        // 1.1 获取每个分类下的SKU明细
        $categoryIds = array_column($categoryStats, 'category_id');
        $skuDetails = [];
        if (!empty($categoryIds)) {
            $skuDetailQuery = Db::name('wanlshop_goods_sku')
                ->alias('sku')
                ->join("{$prefix}wanlshop_goods g", "sku.goods_id = g.id", 'INNER')
                ->join("{$prefix}wanlshop_shop s", "s.id = g.shop_id", 'LEFT')
                ->where('g.category_id', 'in', $categoryIds)
                ->where('g.deletetime', null)
                ->where('g.status', 'normal')
                ->where('sku.deletetime', null)
                ->where('sku.state', '0')
                ->where('sku.price', '>', 0);

            if ($cityName !== '') {
                $skuDetailQuery->where('g.region_city_name', $cityName);
            }

            $skuList = $skuDetailQuery
                ->field([
                    'sku.id',
                    'g.category_id',
                    'g.shop_id',
                    'CASE WHEN g.shop_id = 1 THEN "平台概念店" ELSE IFNULL(s.shopname, "未知店铺") END as shop_name',
                    'g.region_city_name as city_name',
                    'g.title as goods_title',
                    'sku.difference',
                    'sku.price'
                ])
                ->order('g.category_id ASC, g.shop_id ASC, sku.price ASC')
                ->select();

            // 按分类ID分组
            foreach ($skuList as $sku) {
                $catId = $sku['category_id'];
                if (!isset($skuDetails[$catId])) {
                    $skuDetails[$catId] = [];
                }
                $skuDetails[$catId][] = $sku;
            }

            // 重组数据结构：按城市分组，平台SKU为主干，商家SKU为子项
            foreach ($skuDetails as $catId => &$skus) {
                $cityGrouped = [];

                // 先按城市和规格分组所有SKU
                $platformSkus = [];  // 平台SKU，按城市+规格分组
                $merchantSkus = [];  // 商家SKU，按城市+规格分组

                foreach ($skus as $sku) {
                    $cityName = $sku['city_name'] ?: '未知城市';
                    $spec = $sku['difference'];
                    $key = $cityName . '||' . $spec;

                    if ($sku['shop_id'] == 1) {
                        // 平台SKU
                        if (!isset($platformSkus[$cityName])) {
                            $platformSkus[$cityName] = [];
                        }
                        $platformSkus[$cityName][] = [
                            'id' => $sku['id'],
                            'goods_title' => $sku['goods_title'],
                            'difference' => $spec,
                            'price' => $sku['price'],
                            'city_name' => $cityName,
                            'merchant_skus' => []  // 待填充
                        ];
                    } else {
                        // 商家SKU，按城市+规格分组
                        if (!isset($merchantSkus[$key])) {
                            $merchantSkus[$key] = [];
                        }
                        $merchantSkus[$key][] = [
                            'id' => $sku['id'],
                            'shop_name' => $sku['shop_name'],
                            'goods_title' => $sku['goods_title'],
                            'price' => $sku['price']
                        ];
                    }
                }

                // 将商家SKU附加到对应的平台SKU下
                foreach ($platformSkus as $cityName => &$cityPlatformSkus) {
                    foreach ($cityPlatformSkus as &$pSku) {
                        $key = $cityName . '||' . $pSku['difference'];
                        if (isset($merchantSkus[$key])) {
                            $pSku['merchant_skus'] = $merchantSkus[$key];
                            $pSku['merchant_count'] = count($merchantSkus[$key]);
                            // 计算商家平均价
                            $totalPrice = 0;
                            foreach ($merchantSkus[$key] as $mSku) {
                                $totalPrice += floatval($mSku['price']);
                            }
                            $pSku['merchant_avg_price'] = round($totalPrice / count($merchantSkus[$key]), 2);
                        } else {
                            $pSku['merchant_count'] = 0;
                            $pSku['merchant_avg_price'] = null;
                        }
                    }
                    unset($pSku);
                }
                unset($cityPlatformSkus);

                $skuDetails[$catId] = [
                    'by_city' => $platformSkus,
                    'city_list' => array_keys($platformSkus)
                ];
            }
            unset($skus);

            // 生成价差汇总表数据（扁平化：城市+分类+规格）
            $priceDiffSummary = [];
            foreach ($skuDetails as $catId => $catData) {
                // 找到分类名称
                $catName = '';
                foreach ($categoryStats as $cs) {
                    if ($cs['category_id'] == $catId) {
                        $catName = $cs['category_name'];
                        break;
                    }
                }

                foreach ($catData['by_city'] as $cityName => $platformSkuList) {
                    foreach ($platformSkuList as $pSku) {
                        if ($pSku['merchant_count'] > 0) {
                            $diffRatio = round(($pSku['merchant_avg_price'] / $pSku['price']) * 100, 0);
                            $priceDiffSummary[] = [
                                'category_id' => $catId,
                                'category_name' => $catName,
                                'city_name' => $cityName,
                                'sku_id' => $pSku['id'],
                                'difference' => $pSku['difference'],
                                'platform_price' => $pSku['price'],
                                'merchant_avg_price' => $pSku['merchant_avg_price'],
                                'diff_ratio' => $diffRatio,
                                'merchant_count' => $pSku['merchant_count']
                            ];
                        }
                    }
                }
            }

            // 按价差比例排序（从低到高，越低说明商家越便宜）
            usort($priceDiffSummary, function($a, $b) {
                return $a['diff_ratio'] - $b['diff_ratio'];
            });
        }

        // 将SKU明细附加到分类统计中
        foreach ($categoryStats as &$cat) {
            $cat['sku_list'] = isset($skuDetails[$cat['category_id']]) ? $skuDetails[$cat['category_id']] : [];
        }
        unset($cat);

        // 2. 获取按日期聚合的全品类价格趋势（只统计上架商品的SKU更新记录）
        $dailyTrendQuery = Db::name('wanlshop_goods_sku')
            ->alias('sku')
            ->join("{$prefix}wanlshop_goods g", "sku.goods_id = g.id", 'INNER')
            ->where('g.deletetime', null)
            ->where('g.status', 'normal')  // 只统计上架商品
            ->where('sku.deletetime', null)
            ->where('sku.updatetime', 'between', [$startTime, $endTime])
            ->where('sku.price', '>', 0);

        if ($cityName !== '') {
            $dailyTrendQuery->where('g.region_city_name', $cityName);
        }

        $dailyTrend = $dailyTrendQuery
            ->group('update_date')
            ->field([
                'FROM_UNIXTIME(sku.updatetime, "%Y-%m-%d") as update_date',
                'AVG(CASE WHEN g.shop_id = 1 THEN sku.price ELSE NULL END) as platform_avg',
                'AVG(CASE WHEN g.shop_id != 1 THEN sku.price ELSE NULL END) as merchant_avg',
                'MIN(CASE WHEN g.shop_id != 1 THEN sku.price ELSE NULL END) as merchant_min',
                'MAX(CASE WHEN g.shop_id != 1 THEN sku.price ELSE NULL END) as merchant_max',
                'COUNT(*) as record_count'
            ])
            ->order('update_date ASC')
            ->select();

        // 转换为以日期为键的数组
        $dailyMap = [];
        foreach ($dailyTrend as $row) {
            $dailyMap[$row['update_date']] = $row;
        }

        // 构建图表数据（填充没有数据的日期）
        $chartDates = [];
        $platformPrices = [];
        $merchantAvgPrices = [];
        $merchantMinPrices = [];
        $merchantMaxPrices = [];

        // 用于填充空白日期的最后有效值
        $lastPlatform = null;
        $lastMerchantAvg = null;
        $lastMerchantMin = null;
        $lastMerchantMax = null;

        foreach ($dates as $date) {
            $chartDates[] = $date;
            if (isset($dailyMap[$date])) {
                $row = $dailyMap[$date];
                $lastPlatform = $row['platform_avg'] ? round($row['platform_avg'], 2) : $lastPlatform;
                $lastMerchantAvg = $row['merchant_avg'] ? round($row['merchant_avg'], 2) : $lastMerchantAvg;
                $lastMerchantMin = $row['merchant_min'] ? round($row['merchant_min'], 2) : $lastMerchantMin;
                $lastMerchantMax = $row['merchant_max'] ? round($row['merchant_max'], 2) : $lastMerchantMax;
            }
            $platformPrices[] = $lastPlatform;
            $merchantAvgPrices[] = $lastMerchantAvg;
            $merchantMinPrices[] = $lastMerchantMin;
            $merchantMaxPrices[] = $lastMerchantMax;
        }

        // 3. 计算总体统计（只统计上架商品）
        $totalStatsQuery = Db::name('wanlshop_goods_sku')
            ->alias('sku')
            ->join("{$prefix}wanlshop_goods g", "sku.goods_id = g.id", 'INNER')
            ->where('g.deletetime', null)
            ->where('g.status', 'normal')  // 只统计上架商品
            ->where('sku.deletetime', null)
            ->where('sku.state', '0')
            ->where('sku.price', '>', 0);

        if ($cityName !== '') {
            $totalStatsQuery->where('g.region_city_name', $cityName);
        }

        $totalStats = $totalStatsQuery
            ->field([
                'AVG(CASE WHEN g.shop_id = 1 THEN sku.price ELSE NULL END) as platform_avg',
                'AVG(CASE WHEN g.shop_id != 1 THEN sku.price ELSE NULL END) as merchant_avg',
                'MIN(CASE WHEN g.shop_id = 1 THEN sku.price ELSE NULL END) as platform_min',
                'MAX(CASE WHEN g.shop_id = 1 THEN sku.price ELSE NULL END) as platform_max',
                'MIN(CASE WHEN g.shop_id != 1 THEN sku.price ELSE NULL END) as merchant_min',
                'MAX(CASE WHEN g.shop_id != 1 THEN sku.price ELSE NULL END) as merchant_max',
                'COUNT(CASE WHEN g.shop_id = 1 THEN sku.id ELSE NULL END) as platform_sku_count',
                'COUNT(CASE WHEN g.shop_id != 1 THEN sku.id ELSE NULL END) as merchant_sku_count',
                'COUNT(DISTINCT g.category_id) as category_count',
                'COUNT(DISTINCT g.id) as goods_count',
                'COUNT(sku.id) as sku_count',
                'COUNT(DISTINCT CASE WHEN g.shop_id != 1 THEN g.shop_id ELSE NULL END) as merchant_count',
                'COUNT(DISTINCT CASE WHEN g.shop_id != 1 THEN g.region_city_name ELSE NULL END) as city_count'
            ])
            ->find();

        // 计算价差
        $priceDiff = null;
        $diffPercentage = null;
        if ($totalStats['platform_avg'] && $totalStats['merchant_avg']) {
            $priceDiff = round($totalStats['platform_avg'] - $totalStats['merchant_avg'], 2);
            $diffPercentage = round($priceDiff / $totalStats['merchant_avg'] * 100, 2);
        }

        $this->success('', null, [
            'date_range' => ['start' => $startDate, 'end' => $endDate],
            'total_stats' => [
                'platform_avg' => $totalStats['platform_avg'] ? round($totalStats['platform_avg'], 2) : null,
                'platform_min' => $totalStats['platform_min'] ? round($totalStats['platform_min'], 2) : null,
                'platform_max' => $totalStats['platform_max'] ? round($totalStats['platform_max'], 2) : null,
                'platform_sku_count' => intval($totalStats['platform_sku_count']),
                'merchant_avg' => $totalStats['merchant_avg'] ? round($totalStats['merchant_avg'], 2) : null,
                'merchant_min' => $totalStats['merchant_min'] ? round($totalStats['merchant_min'], 2) : null,
                'merchant_max' => $totalStats['merchant_max'] ? round($totalStats['merchant_max'], 2) : null,
                'merchant_sku_count' => intval($totalStats['merchant_sku_count']),
                'price_diff' => $priceDiff,
                'diff_percentage' => $diffPercentage,
                'category_count' => $totalStats['category_count'],
                'goods_count' => $totalStats['goods_count'],
                'sku_count' => $totalStats['sku_count'],
                'merchant_count' => $totalStats['merchant_count'],
                'city_count' => $totalStats['city_count']
            ],
            'category_stats' => $categoryStats,
            'price_diff_summary' => isset($priceDiffSummary) ? $priceDiffSummary : [],
            'chart_data' => [
                'dates' => $chartDates,
                'platform_prices' => $platformPrices,
                'merchant_avg_prices' => $merchantAvgPrices,
                'merchant_min_prices' => $merchantMinPrices,
                'merchant_max_prices' => $merchantMaxPrices
            ]
        ]);
    }

    /**
     * 获取分类列表 (AJAX)
     */
    public function getCategoryList()
    {
        $prefix = Config::get('database.prefix');
        $categories = Db::name('wanlshop_category')
            ->alias('c')
            ->join("{$prefix}wanlshop_goods g", "g.category_id = c.id AND g.deletetime IS NULL AND g.status = 'normal'", 'INNER')
            ->where('c.type', 'goods')
            ->where('c.status', 'normal')
            ->group('c.id')
            ->field('c.id, c.name')
            ->order('c.weigh DESC, c.id ASC')
            ->select();

        $this->success('', null, $categories);
    }

    /**
     * 获取有商品的城市列表 (AJAX)
     */
    public function getCityList()
    {
        $prefix = Config::get('database.prefix');

        $cities = Db::name('wanlshop_goods')
            ->alias('g')
            ->join("{$prefix}wanlshop_goods_sku sku", "sku.goods_id = g.id AND sku.deletetime IS NULL AND sku.state = '0'", 'INNER')
            ->where('g.deletetime', null)
            ->where('g.status', 'normal')
            ->where('g.region_city_name', 'neq', '')
            ->where('g.region_city_name', 'exp', 'IS NOT NULL')
            ->group('g.region_city_code, g.region_city_name')
            ->field('g.region_city_code as code, g.region_city_name as name')
            ->order('g.region_city_name ASC')
            ->select();

        $this->success('', null, $cities);
    }

    public function getSpecList()
    {
        $categoryId = $this->request->param('category_id', 0, 'intval');
        $cityName = $this->request->param('city_name', '');

        if (!$categoryId) {
            $this->error('请选择分类');
        }

        $prefix = Config::get('database.prefix');

        // 获取该分类下所有上架商品的在售SKU规格
        $specs = Db::name('wanlshop_goods_sku')
            ->alias('sku')
            ->join("{$prefix}wanlshop_goods g", "sku.goods_id = g.id", 'INNER')
            ->where('g.category_id', $categoryId)
            ->where('g.deletetime', null)
            ->where('g.status', 'normal')  // 只统计上架商品
            ->where('sku.deletetime', null)
            ->where('sku.state', '0');  // 在售SKU

        if ($cityName !== '') {
            $specs->where('g.region_city_name', $cityName);
        }

        $specs = $specs
            ->group('sku.difference')
            ->field('sku.difference')
            ->order('sku.difference ASC')
            ->select();

        $result = [];
        foreach ($specs as $spec) {
            $result[] = [
                'value' => $spec['difference'],
                'label' => $spec['difference']
            ];
        }

        $this->success('', null, $result);
    }

    public function getTrendData()
    {
        $categoryId = $this->request->param('category_id', 0, 'intval');
        $startDate = $this->request->param('start_date', '');
        $endDate = $this->request->param('end_date', '');

        if (!$categoryId) {
            $this->error('请选择分类');
        }

        // 默认显示最近30天
        if (!$startDate) {
            $startDate = date('Y-m-d', strtotime('-30 days'));
        }
        if (!$endDate) {
            $endDate = date('Y-m-d');
        }

        $startTime = strtotime($startDate . ' 00:00:00');
        $endTime = strtotime($endDate . ' 23:59:59');

        $prefix = Config::get('database.prefix');

        // 生成日期序列
        $dates = [];
        $current = strtotime($startDate);
        $end = strtotime($endDate);
        while ($current <= $end) {
            $dates[] = date('Y-m-d', $current);
            $current += 86400;
        }

        // 获取分类名称
        $categoryName = Db::name('wanlshop_category')->where('id', $categoryId)->value('name');

        // 获取该分类下所有SKU的历史价格数据（包括当前state=0和历史state=1）
        // 只统计在售商品（有state=0记录的SKU）
        $skuQuery = Db::name('wanlshop_goods_sku')
            ->alias('sku')
            ->join("{$prefix}wanlshop_goods g", "sku.goods_id = g.id", 'INNER')
            ->join("{$prefix}wanlshop_shop s", "s.id = g.shop_id", 'LEFT')
            ->where('g.category_id', $categoryId)
            ->where('g.deletetime', null)
            ->where('g.status', 'normal')
            ->where('sku.deletetime', null)
            ->where('sku.price', '>', 0);

        // 获取原始数据
        $rawData = $skuQuery->field([
            'sku.id',
            'sku.goods_id',
            'g.shop_id',
            'CASE WHEN g.shop_id = 1 THEN "平台概念店" ELSE IFNULL(s.shopname, "未知店铺") END as shop_name',
            'g.region_city_name as city_name',
            'g.title as goods_title',
            'sku.difference',
            'sku.price',
            'sku.state',
            'sku.createtime',
            'sku.updatetime'
        ])->order('sku.createtime ASC')->select();

        // 按城市 > 规格 组织数据
        $cityData = [];
        $cityList = [];

        foreach ($rawData as $row) {
            $cityName = $row['city_name'] ?: '未知城市';
            $spec = $row['difference'];
            $isPlatform = ($row['shop_id'] == 1);

            if (!in_array($cityName, $cityList)) {
                $cityList[] = $cityName;
            }

            if (!isset($cityData[$cityName])) {
                $cityData[$cityName] = [];
            }
            if (!isset($cityData[$cityName][$spec])) {
                $cityData[$cityName][$spec] = [
                    'platform_records' => [],  // 平台价格记录
                    'platform_info' => null,   // 平台商品信息
                    'merchant_records' => [],  // 商家价格记录（按goods_id分组）
                    'merchant_infos' => [],    // 商家商品信息
                    'current_platform_price' => null,
                    'current_merchant_skus' => []
                ];
            }

            // 记录价格变动时间点
            $timestamp = $row['createtime'];
            $price = floatval($row['price']);

            if ($isPlatform) {
                $cityData[$cityName][$spec]['platform_records'][] = [
                    'time' => $timestamp,
                    'price' => $price
                ];
                // 记录平台商品信息
                if (!$cityData[$cityName][$spec]['platform_info']) {
                    $cityData[$cityName][$spec]['platform_info'] = [
                        'goods_id' => $row['goods_id'],
                        'goods_title' => $row['goods_title']
                    ];
                }
                if ($row['state'] == '0') {
                    $cityData[$cityName][$spec]['current_platform_price'] = $price;
                }
            } else {
                // 商家按 goods_id 分组（每个商品一条线）
                $goodsId = $row['goods_id'];
                $shopId = $row['shop_id'];

                if (!isset($cityData[$cityName][$spec]['merchant_records'][$goodsId])) {
                    $cityData[$cityName][$spec]['merchant_records'][$goodsId] = [
                        'shop_id' => $shopId,
                        'shop_name' => $row['shop_name'],
                        'goods_title' => $row['goods_title'],
                        'records' => []
                    ];
                }
                $cityData[$cityName][$spec]['merchant_records'][$goodsId]['records'][] = [
                    'time' => $timestamp,
                    'price' => $price
                ];

                if ($row['state'] == '0') {
                    $cityData[$cityName][$spec]['current_merchant_skus'][] = [
                        'id' => $row['id'],
                        'goods_id' => $goodsId,
                        'shop_id' => $shopId,
                        'shop_name' => $row['shop_name'],
                        'goods_title' => $row['goods_title'],
                        'price' => $price
                    ];
                }
            }
        }

        // 生成折线图数据
        $chartDataByCity = [];

        foreach ($cityData as $cityName => $specData) {
            $chartDataByCity[$cityName] = [];

            foreach ($specData as $spec => $data) {
                // 生成平台价格时间线
                $platformPrices = $this->generatePriceLine($data['platform_records'], $dates, $startTime, $endTime);

                // 生成每个商家SKU的价格时间线
                $merchantLines = [];
                $shopList = [];  // 店铺列表，用于 checkbox
                $shopIds = [];   // 去重

                foreach ($data['merchant_records'] as $goodsId => $merchantData) {
                    $merchantPrices = $this->generatePriceLine($merchantData['records'], $dates, $startTime, $endTime);
                    $merchantLines[] = [
                        'goods_id' => $goodsId,
                        'shop_id' => $merchantData['shop_id'],
                        'shop_name' => $merchantData['shop_name'],
                        'goods_title' => $merchantData['goods_title'],
                        'prices' => $merchantPrices
                    ];

                    // 收集店铺列表
                    if (!in_array($merchantData['shop_id'], $shopIds)) {
                        $shopIds[] = $merchantData['shop_id'];
                        $shopList[] = [
                            'shop_id' => $merchantData['shop_id'],
                            'shop_name' => $merchantData['shop_name']
                        ];
                    }
                }

                // 生成商家均价时间线
                $merchantAvgPrices = $this->generateMerchantAvgLine($data['merchant_records'], $dates, $startTime, $endTime);

                // 计算当前统计
                $currentPlatformPrice = $data['current_platform_price'];
                $currentMerchantSkus = $data['current_merchant_skus'];
                $currentMerchantAvg = null;
                $merchantCount = count($currentMerchantSkus);

                if ($merchantCount > 0) {
                    $totalPrice = 0;
                    foreach ($currentMerchantSkus as $mSku) {
                        $totalPrice += $mSku['price'];
                    }
                    $currentMerchantAvg = round($totalPrice / $merchantCount, 2);
                }

                // 计算价差百分比：(平台价-商家均价)/商家均价 * 100
                // 正数表示平台更贵，负数表示平台更便宜
                $diffRatio = null;
                if ($currentMerchantAvg && $currentMerchantAvg > 0) {
                    $diffRatio = round((($currentPlatformPrice - $currentMerchantAvg) / $currentMerchantAvg) * 100, 1);
                }

                $chartDataByCity[$cityName][$spec] = [
                    'spec' => $spec,
                    'dates' => $dates,
                    'platform' => [
                        'goods_id' => $data['platform_info'] ? $data['platform_info']['goods_id'] : null,
                        'goods_title' => $data['platform_info'] ? $data['platform_info']['goods_title'] : null,
                        'prices' => $platformPrices
                    ],
                    'merchants' => $merchantLines,
                    'merchant_avg_prices' => $merchantAvgPrices,
                    'shop_list' => $shopList,
                    'current_stats' => [
                        'platform_price' => $currentPlatformPrice,
                        'merchant_avg' => $currentMerchantAvg,
                        'diff_ratio' => $diffRatio,
                        'merchant_count' => $merchantCount
                    ],
                    'current_merchant_skus' => $currentMerchantSkus
                ];
            }
        }

        sort($cityList);

        $this->success('', null, [
            'category_id' => $categoryId,
            'category_name' => $categoryName,
            'date_range' => ['start' => $startDate, 'end' => $endDate],
            'city_list' => $cityList,
            'chart_data_by_city' => $chartDataByCity
        ]);
    }

    /**
     * 生成价格时间线（填充空白日期）
     */
    protected function generatePriceLine($records, $dates, $startTime, $endTime)
    {
        if (empty($records)) {
            return array_fill(0, count($dates), null);
        }

        // 按时间排序
        usort($records, function($a, $b) {
            return $a['time'] - $b['time'];
        });

        $priceTimeline = [];
        $lastPrice = null;

        // 查找开始日期之前的最新价格作为初始值
        foreach ($records as $record) {
            if ($record['time'] < $startTime) {
                $lastPrice = $record['price'];
            }
        }

        $recordIndex = 0;
        $recordCount = count($records);

        foreach ($dates as $date) {
            $dateEnd = strtotime($date . ' 23:59:59');

            // 更新到该日期结束时的最新价格
            while ($recordIndex < $recordCount && $records[$recordIndex]['time'] <= $dateEnd) {
                $lastPrice = $records[$recordIndex]['price'];
                $recordIndex++;
            }

            $priceTimeline[] = $lastPrice;
        }

        return $priceTimeline;
    }

    /**
     * 生成商家均价时间线
     */
    protected function generateMerchantAvgLine($merchantRecords, $dates, $startTime, $endTime)
    {
        if (empty($merchantRecords)) {
            return array_fill(0, count($dates), null);
        }

        // 每个商家维护自己的价格状态
        $merchantPrices = [];  // [shop_id => ['last_price' => x, 'records' => sorted_records, 'index' => i]]

        foreach ($merchantRecords as $shopId => $shopData) {
            $records = $shopData['records'];
            usort($records, function($a, $b) {
                return $a['time'] - $b['time'];
            });

            // 查找开始日期之前的最新价格
            $lastPrice = null;
            foreach ($records as $record) {
                if ($record['time'] < $startTime) {
                    $lastPrice = $record['price'];
                }
            }

            $merchantPrices[$shopId] = [
                'last_price' => $lastPrice,
                'records' => $records,
                'index' => 0
            ];
        }

        $avgTimeline = [];

        foreach ($dates as $date) {
            $dateEnd = strtotime($date . ' 23:59:59');
            $dayPrices = [];

            foreach ($merchantPrices as $shopId => &$mp) {
                // 更新到该日期的最新价格
                while ($mp['index'] < count($mp['records']) && $mp['records'][$mp['index']]['time'] <= $dateEnd) {
                    $mp['last_price'] = $mp['records'][$mp['index']]['price'];
                    $mp['index']++;
                }

                if ($mp['last_price'] !== null) {
                    $dayPrices[] = $mp['last_price'];
                }
            }
            unset($mp);

            if (count($dayPrices) > 0) {
                $avgTimeline[] = round(array_sum($dayPrices) / count($dayPrices), 2);
            } else {
                $avgTimeline[] = null;
            }
        }

        return $avgTimeline;
    }

    public function getDetailList()
    {
        $categoryId = $this->request->param('category_id', 0, 'intval');
        $spec = $this->request->param('spec', '');
        $date = $this->request->param('date', '');
        $cityName = $this->request->param('city_name', '');

        if (!$categoryId) {
            $this->error('请选择分类');
        }

        $prefix = Config::get('database.prefix');

        // 构建查询（只统计上架商品）
        $query = Db::name('wanlshop_goods_sku')
            ->alias('sku')
            ->join("{$prefix}wanlshop_goods g", "sku.goods_id = g.id", 'INNER')
            ->join("{$prefix}wanlshop_shop s", "s.id = g.shop_id", 'LEFT')
            ->where('g.category_id', $categoryId)
            ->where('g.deletetime', null)
            ->where('g.status', 'normal')  // 只统计上架商品
            ->where('sku.deletetime', null)
            ->where('sku.state', '0');  // 当前在售

        if ($spec) {
            $query->where('sku.difference', $spec);
        }

        if ($cityName !== '') {
            $query->where('g.region_city_name', $cityName);
        }

        $list = $query->field([
            'sku.id',
            'sku.goods_id',
            'g.shop_id',
            'CASE WHEN g.shop_id = 1 THEN "平台概念店" ELSE IFNULL(s.shopname, "未知店铺") END as shop_name',
            'g.title as goods_title',
            'sku.difference',
            'sku.price',
            'FROM_UNIXTIME(sku.updatetime, "%Y-%m-%d %H:%i:%s") as update_time'
        ])->order('g.shop_id ASC, sku.price ASC')->select();

        $this->success('', null, $list);
    }

    /**
     * 获取市场价概览数据
     *
     * 统计所有店铺所有SKU的市场价（market_price）明细和均值
     * 支持按城市、分类、规格筛选
     * 支持历史市场价趋势查询
     */
    public function getMarketPriceOverview()
    {
        $cityName = $this->request->param('city_name', '');
        $categoryId = $this->request->param('category_id', 0, 'intval');
        $spec = $this->request->param('spec', '');
        $startDate = $this->request->param('start_date', '');
        $endDate = $this->request->param('end_date', '');

        // 默认显示最近30天
        if (!$startDate) {
            $startDate = date('Y-m-d', strtotime('-30 days'));
        }
        if (!$endDate) {
            $endDate = date('Y-m-d');
        }

        // 转换为时间戳
        $startTime = strtotime($startDate . ' 00:00:00');
        $endTime = strtotime($endDate . ' 23:59:59');

        // 生成日期序列
        $dates = [];
        $current = strtotime($startDate);
        $end = strtotime($endDate);
        while ($current <= $end) {
            $dates[] = date('Y-m-d', $current);
            $current += 86400;
        }

        $prefix = Config::get('database.prefix');

        // ========== 1. 总体统计 ==========
        $totalStatsQuery = Db::name('wanlshop_goods_sku')
            ->alias('sku')
            ->join("{$prefix}wanlshop_goods g", "sku.goods_id = g.id", 'INNER')
            ->where('g.deletetime', null)
            ->where('g.status', 'normal')
            ->where('sku.deletetime', null)
            ->where('sku.state', '0')
            ->where('sku.market_price', '>', 0);

        if ($cityName !== '') {
            $totalStatsQuery->where('g.region_city_name', $cityName);
        }
        if ($categoryId > 0) {
            $totalStatsQuery->where('g.category_id', $categoryId);
        }
        if ($spec !== '') {
            $totalStatsQuery->where('sku.difference', $spec);
        }

        $totalStats = $totalStatsQuery
            ->field([
                'AVG(sku.market_price) as avg_market_price',
                'MIN(sku.market_price) as min_market_price',
                'MAX(sku.market_price) as max_market_price',
                'COUNT(sku.id) as sku_count',
                'COUNT(DISTINCT g.shop_id) as shop_count',
                'COUNT(DISTINCT g.region_city_name) as city_count',
                'COUNT(DISTINCT g.category_id) as category_count',
                'COUNT(DISTINCT g.id) as goods_count'
            ])
            ->find();

        // ========== 2. 按城市统计 ==========
        $cityStatsQuery = Db::name('wanlshop_goods_sku')
            ->alias('sku')
            ->join("{$prefix}wanlshop_goods g", "sku.goods_id = g.id", 'INNER')
            ->where('g.deletetime', null)
            ->where('g.status', 'normal')
            ->where('sku.deletetime', null)
            ->where('sku.state', '0')
            ->where('sku.market_price', '>', 0)
            ->where('g.region_city_name', 'neq', '')
            ->where('g.region_city_name', 'exp', 'IS NOT NULL');

        if ($categoryId > 0) {
            $cityStatsQuery->where('g.category_id', $categoryId);
        }
        if ($spec !== '') {
            $cityStatsQuery->where('sku.difference', $spec);
        }

        $cityStats = $cityStatsQuery
            ->group('g.region_city_name')
            ->field([
                'g.region_city_name as city_name',
                'AVG(sku.market_price) as avg_market_price',
                'MIN(sku.market_price) as min_market_price',
                'MAX(sku.market_price) as max_market_price',
                'COUNT(sku.id) as sku_count',
                'COUNT(DISTINCT g.shop_id) as shop_count',
                'COUNT(DISTINCT g.id) as goods_count'
            ])
            ->order('avg_market_price DESC')
            ->select();

        // 格式化城市统计数据
        foreach ($cityStats as &$city) {
            $city['avg_market_price'] = round(floatval($city['avg_market_price']), 2);
            $city['min_market_price'] = round(floatval($city['min_market_price']), 2);
            $city['max_market_price'] = round(floatval($city['max_market_price']), 2);
        }
        unset($city);

        // ========== 3. 按城市+分类+规格明细 ==========
        $detailQuery = Db::name('wanlshop_goods_sku')
            ->alias('sku')
            ->join("{$prefix}wanlshop_goods g", "sku.goods_id = g.id", 'INNER')
            ->join("{$prefix}wanlshop_category c", "c.id = g.category_id", 'LEFT')
            ->where('g.deletetime', null)
            ->where('g.status', 'normal')
            ->where('sku.deletetime', null)
            ->where('sku.state', '0')
            ->where('sku.market_price', '>', 0);

        if ($cityName !== '') {
            $detailQuery->where('g.region_city_name', $cityName);
        }
        if ($categoryId > 0) {
            $detailQuery->where('g.category_id', $categoryId);
        }
        if ($spec !== '') {
            $detailQuery->where('sku.difference', $spec);
        }

        $detailList = $detailQuery
            ->group('g.region_city_name, g.category_id, sku.difference')
            ->field([
                'g.region_city_name as city_name',
                'g.category_id',
                'c.name as category_name',
                'sku.difference as spec',
                'AVG(sku.market_price) as avg_market_price',
                'MIN(sku.market_price) as min_market_price',
                'MAX(sku.market_price) as max_market_price',
                'COUNT(sku.id) as sku_count',
                'COUNT(DISTINCT g.shop_id) as shop_count'
            ])
            ->order('g.region_city_name ASC, c.weigh DESC, sku.difference ASC')
            ->select();

        // 获取每个明细项的SKU列表
        foreach ($detailList as &$detail) {
            $detail['avg_market_price'] = round(floatval($detail['avg_market_price']), 2);
            $detail['min_market_price'] = round(floatval($detail['min_market_price']), 2);
            $detail['max_market_price'] = round(floatval($detail['max_market_price']), 2);

            // 获取该组合下的所有SKU明细
            $skuListQuery = Db::name('wanlshop_goods_sku')
                ->alias('sku')
                ->join("{$prefix}wanlshop_goods g", "sku.goods_id = g.id", 'INNER')
                ->join("{$prefix}wanlshop_shop s", "s.id = g.shop_id", 'LEFT')
                ->where('g.region_city_name', $detail['city_name'])
                ->where('g.category_id', $detail['category_id'])
                ->where('sku.difference', $detail['spec'])
                ->where('g.deletetime', null)
                ->where('g.status', 'normal')
                ->where('sku.deletetime', null)
                ->where('sku.state', '0')
                ->where('sku.market_price', '>', 0);

            $detail['sku_list'] = $skuListQuery
                ->field([
                    'sku.id as sku_id',
                    'g.id as goods_id',
                    'g.title as goods_title',
                    'g.shop_id',
                    'CASE WHEN g.shop_id = 1 THEN "平台概念店" ELSE IFNULL(s.shopname, "未知店铺") END as shop_name',
                    'sku.market_price',
                    'sku.price',
                    'sku.stock'
                ])
                ->order('sku.market_price ASC')
                ->select();
        }
        unset($detail);

        // ========== 4. 筛选器选项 ==========
        // 获取有市场价数据的城市列表
        $cities = Db::name('wanlshop_goods')
            ->alias('g')
            ->join("{$prefix}wanlshop_goods_sku sku", "sku.goods_id = g.id AND sku.deletetime IS NULL AND sku.state = '0' AND sku.market_price > 0", 'INNER')
            ->where('g.deletetime', null)
            ->where('g.status', 'normal')
            ->where('g.region_city_name', 'neq', '')
            ->where('g.region_city_name', 'exp', 'IS NOT NULL')
            ->group('g.region_city_name')
            ->field('g.region_city_name as name')
            ->order('g.region_city_name ASC')
            ->select();

        // 获取有市场价数据的分类列表（带层级）
        $categoryIds = Db::name('wanlshop_category')
            ->alias('c')
            ->join("{$prefix}wanlshop_goods g", "g.category_id = c.id AND g.deletetime IS NULL AND g.status = 'normal'", 'INNER')
            ->join("{$prefix}wanlshop_goods_sku sku", "sku.goods_id = g.id AND sku.deletetime IS NULL AND sku.state = '0' AND sku.market_price > 0", 'INNER')
            ->where('c.type', 'goods')
            ->where('c.status', 'normal')
            ->group('c.id')
            ->column('c.id');

        // 获取这些分类及其父级的完整信息
        $allCategories = Db::name('wanlshop_category')
            ->where('type', 'goods')
            ->where('status', 'normal')
            ->field('id, pid, name, weigh')
            ->order('weigh DESC, id ASC')
            ->select();

        // 构建层级树形结构
        $categories = $this->buildCategoryTree($allCategories, $categoryIds);

        // 获取有市场价数据的规格列表（根据当前筛选条件）
        $specsQuery = Db::name('wanlshop_goods_sku')
            ->alias('sku')
            ->join("{$prefix}wanlshop_goods g", "sku.goods_id = g.id", 'INNER')
            ->where('g.deletetime', null)
            ->where('g.status', 'normal')
            ->where('sku.deletetime', null)
            ->where('sku.state', '0')
            ->where('sku.market_price', '>', 0);

        if ($cityName !== '') {
            $specsQuery->where('g.region_city_name', $cityName);
        }
        if ($categoryId > 0) {
            $specsQuery->where('g.category_id', $categoryId);
        }

        $specs = $specsQuery
            ->group('sku.difference')
            ->field('sku.difference as name')
            ->order('sku.difference ASC')
            ->select();

        // ========== 5. 图表数据（柱状图用） ==========
        $chartData = [
            'cities' => array_column($cityStats, 'city_name'),
            'avg_prices' => array_column($cityStats, 'avg_market_price'),
            'min_prices' => array_column($cityStats, 'min_market_price'),
            'max_prices' => array_column($cityStats, 'max_market_price'),
            'sku_counts' => array_column($cityStats, 'sku_count')
        ];

        // ========== 6. 历史市场价趋势数据（折线图用） ==========
        // 查询所有 SKU 记录（包括历史记录），按城市和日期聚合
        $trendQuery = Db::name('wanlshop_goods_sku')
            ->alias('sku')
            ->join("{$prefix}wanlshop_goods g", "sku.goods_id = g.id", 'INNER')
            ->where('g.deletetime', null)
            ->where('g.status', 'normal')
            ->where('sku.deletetime', null)
            ->where('sku.market_price', '>', 0)
            ->where('g.region_city_name', 'neq', '')
            ->where('g.region_city_name', 'exp', 'IS NOT NULL');

        if ($cityName !== '') {
            $trendQuery->where('g.region_city_name', $cityName);
        }
        if ($categoryId > 0) {
            $trendQuery->where('g.category_id', $categoryId);
        }
        if ($spec !== '') {
            $trendQuery->where('sku.difference', $spec);
        }

        // 获取原始数据（包含历史和当前记录）
        $rawTrendData = $trendQuery
            ->field([
                'sku.id',
                'g.region_city_name as city_name',
                'sku.market_price',
                'sku.state',
                'sku.createtime'
            ])
            ->order('g.region_city_name ASC, sku.createtime ASC')
            ->select();

        // 按城市组织数据
        $cityTrendData = [];
        foreach ($rawTrendData as $row) {
            $city = $row['city_name'];
            if (!isset($cityTrendData[$city])) {
                $cityTrendData[$city] = [];
            }
            $cityTrendData[$city][] = [
                'time' => intval($row['createtime']),
                'price' => floatval($row['market_price'])
            ];
        }

        // 生成每个城市的时间序列
        $trendChartData = [
            'dates' => $dates,
            'series' => []
        ];

        foreach ($cityTrendData as $city => $records) {
            $priceTimeline = $this->generateMarketPriceLine($records, $dates, $startTime, $endTime);
            $trendChartData['series'][] = [
                'name' => $city,
                'data' => $priceTimeline
            ];
        }

        $this->success('', null, [
            'date_range' => ['start' => $startDate, 'end' => $endDate],
            'total_stats' => [
                'avg_market_price' => $totalStats['avg_market_price'] ? round(floatval($totalStats['avg_market_price']), 2) : 0,
                'min_market_price' => $totalStats['min_market_price'] ? round(floatval($totalStats['min_market_price']), 2) : 0,
                'max_market_price' => $totalStats['max_market_price'] ? round(floatval($totalStats['max_market_price']), 2) : 0,
                'sku_count' => intval($totalStats['sku_count']),
                'shop_count' => intval($totalStats['shop_count']),
                'city_count' => intval($totalStats['city_count']),
                'category_count' => intval($totalStats['category_count']),
                'goods_count' => intval($totalStats['goods_count'])
            ],
            'city_stats' => $cityStats,
            'detail_list' => $detailList,
            'filter_options' => [
                'cities' => $cities,
                'categories' => $categories,
                'specs' => $specs
            ],
            'chart_data' => $chartData,
            'trend_chart_data' => $trendChartData
        ]);
    }

    /**
     * 生成市场价时间序列（填充空白日期，计算每日均价）
     */
    protected function generateMarketPriceLine($records, $dates, $startTime, $endTime)
    {
        if (empty($records)) {
            return array_fill(0, count($dates), null);
        }

        // 按时间排序
        usort($records, function($a, $b) {
            return $a['time'] - $b['time'];
        });

        $priceTimeline = [];

        // 先统计每个日期所有记录的价格，计算当天均价
        $dailyPrices = [];
        foreach ($records as $record) {
            $date = date('Y-m-d', $record['time']);
            if (!isset($dailyPrices[$date])) {
                $dailyPrices[$date] = [];
            }
            $dailyPrices[$date][] = $record['price'];
        }

        // 计算每天的均价
        $dailyAvgPrices = [];
        foreach ($dailyPrices as $date => $prices) {
            $dailyAvgPrices[$date] = round(array_sum($prices) / count($prices), 2);
        }

        // 获取开始日期之前最近的价格作为初始值
        $lastPrice = null;
        foreach ($records as $record) {
            if ($record['time'] < $startTime) {
                $lastPrice = $record['price'];
            }
        }

        // 填充日期序列
        foreach ($dates as $date) {
            if (isset($dailyAvgPrices[$date])) {
                $lastPrice = $dailyAvgPrices[$date];
            }
            $priceTimeline[] = $lastPrice;
        }

        return $priceTimeline;
    }

    /**
     * 构建分类层级树
     * @param array $allCategories 所有分类
     * @param array $validIds 有数据的分类ID
     * @return array 带层级的分类列表
     */
    protected function buildCategoryTree($allCategories, $validIds)
    {
        // 构建索引
        $categoryMap = [];
        foreach ($allCategories as $cat) {
            $categoryMap[$cat['id']] = $cat;
        }

        // 找出需要显示的分类（有数据的分类及其父级）
        $showIds = [];
        foreach ($validIds as $id) {
            $showIds[$id] = true;
            // 向上追溯父级
            $current = $id;
            while (isset($categoryMap[$current]) && $categoryMap[$current]['pid'] > 0) {
                $parentId = $categoryMap[$current]['pid'];
                $showIds[$parentId] = true;
                $current = $parentId;
            }
        }

        // 按层级排序输出（先父后子）
        $result = [];
        $this->addCategoryChildren($result, $allCategories, 0, 0, $showIds, $validIds);

        return $result;
    }

    /**
     * 递归添加子分类
     */
    protected function addCategoryChildren(&$result, $allCategories, $parentId, $level, $showIds, $validIds)
    {
        foreach ($allCategories as $cat) {
            if ($cat['pid'] == $parentId && isset($showIds[$cat['id']])) {
                // 生成层级前缀
                $prefix = $level > 0 ? str_repeat('　', $level) . '└ ' : '';
                $result[] = [
                    'id' => $cat['id'],
                    'name' => $prefix . $cat['name'],
                    'level' => $level,
                    'has_data' => in_array($cat['id'], $validIds),  // 是否有实际数据
                    'disabled' => !in_array($cat['id'], $validIds)  // 没数据的禁用
                ];
                // 递归添加子分类
                $this->addCategoryChildren($result, $allCategories, $cat['id'], $level + 1, $showIds, $validIds);
            }
        }
    }
}
