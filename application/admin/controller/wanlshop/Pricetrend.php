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
    protected $noNeedRight = ['getCategoryList', 'getSpecList', 'getTrendData', 'getOverviewData', 'getCityList'];

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
                'COUNT(DISTINCT g.category_id) as category_count',
                'COUNT(DISTINCT g.id) as goods_count',
                'COUNT(sku.id) as sku_count',
                'COUNT(DISTINCT CASE WHEN g.shop_id != 1 THEN g.shop_id ELSE NULL END) as merchant_count'
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
                'merchant_avg' => $totalStats['merchant_avg'] ? round($totalStats['merchant_avg'], 2) : null,
                'price_diff' => $priceDiff,
                'diff_percentage' => $diffPercentage,
                'category_count' => $totalStats['category_count'],
                'goods_count' => $totalStats['goods_count'],
                'sku_count' => $totalStats['sku_count'],
                'merchant_count' => $totalStats['merchant_count']
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
        $specs = $this->request->param('specs', '');  // 逗号分隔的规格列表
        $startDate = $this->request->param('start_date', '');
        $endDate = $this->request->param('end_date', '');
        $cityName = $this->request->param('city_name', '');

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

        // 转换为时间戳
        $startTime = strtotime($startDate . ' 00:00:00');
        $endTime = strtotime($endDate . ' 23:59:59');

        $prefix = Config::get('database.prefix');

        // 解析规格列表
        $specList = $specs ? explode(',', $specs) : [];

        // 构建查询：获取所有SKU价格变动记录（包括历史和当前，只统计上架商品）
        $query = Db::name('wanlshop_goods_sku')
            ->alias('sku')
            ->join("{$prefix}wanlshop_goods g", "sku.goods_id = g.id", 'INNER')
            ->where('g.category_id', $categoryId)
            ->where('g.deletetime', null)
            ->where('g.status', 'normal')  // 只统计上架商品
            ->where('sku.deletetime', null)
            ->where('sku.updatetime', 'between', [$startTime, $endTime]);

        if (!empty($specList)) {
            $query->where('sku.difference', 'in', $specList);
        }

        if ($cityName !== '') {
            $query->where('g.region_city_name', $cityName);
        }

        // 获取原始数据
        $rawData = $query->field([
            'sku.id',
            'sku.goods_id',
            'g.shop_id',
            'g.title as goods_title',
            'sku.difference',
            'sku.price',
            'sku.state',
            'sku.updatetime',
            'FROM_UNIXTIME(sku.updatetime, "%Y-%m-%d") as update_date'
        ])->order('sku.updatetime ASC')->select();

        // 按日期和规格聚合数据
        $result = $this->aggregatePriceData($rawData, $startDate, $endDate, $specList);

        // 获取分类名称
        $categoryQuery = Db::name('wanlshop_category')->alias('c')->where('c.id', $categoryId);
        if ($cityName !== '') {
            $categoryQuery->join("{$prefix}wanlshop_goods g", "g.category_id = c.id", 'INNER')
                ->where('g.deletetime', null)
                ->where('g.status', 'normal')
                ->where('g.region_city_name', $cityName);
        }
        $categoryName = $categoryQuery->value('c.name');

        $this->success('', null, [
            'category_name' => $categoryName,
            'date_range' => ['start' => $startDate, 'end' => $endDate],
            'specs' => $result['specs'],
            'chart_data' => $result['chart_data'],
            'summary' => $result['summary']
        ]);
    }

    /**
     * 聚合价格数据
     *
     * @param array $rawData 原始数据
     * @param string $startDate 开始日期
     * @param string $endDate 结束日期
     * @param array $specList 规格列表
     * @return array
     */
    protected function aggregatePriceData($rawData, $startDate, $endDate, $specList)
    {
        // 生成日期序列
        $dates = [];
        $current = strtotime($startDate);
        $end = strtotime($endDate);
        while ($current <= $end) {
            $dates[] = date('Y-m-d', $current);
            $current += 86400;
        }

        // 按规格和日期组织数据
        // 结构：$priceMap[规格][日期] = ['platform' => 价格, 'merchants' => [价格数组]]
        $priceMap = [];
        $allSpecs = [];

        // 首先获取每个商品在每个日期的最新价格状态
        // 需要考虑：如果某天没有更新，应该使用该商品之前的最新价格

        // 按商品+规格分组，找出每个时间点的价格
        $goodsSkuPrices = [];
        foreach ($rawData as $row) {
            $key = $row['goods_id'] . '_' . $row['difference'];
            if (!isset($goodsSkuPrices[$key])) {
                $goodsSkuPrices[$key] = [
                    'shop_id' => $row['shop_id'],
                    'difference' => $row['difference'],
                    'prices' => []  // [timestamp => price]
                ];
            }
            $goodsSkuPrices[$key]['prices'][$row['updatetime']] = $row['price'];

            if (!in_array($row['difference'], $allSpecs)) {
                $allSpecs[] = $row['difference'];
            }
        }

        // 如果没有指定规格，使用所有找到的规格
        if (empty($specList)) {
            $specList = $allSpecs;
        }

        // 为每个规格初始化数据结构
        foreach ($specList as $spec) {
            $priceMap[$spec] = [];
            foreach ($dates as $date) {
                $priceMap[$spec][$date] = [
                    'platform' => null,
                    'merchants' => []
                ];
            }
        }

        // 填充每个日期的价格数据
        foreach ($goodsSkuPrices as $skuData) {
            $spec = $skuData['difference'];
            if (!in_array($spec, $specList)) {
                continue;
            }

            $shopId = $skuData['shop_id'];
            $isPlatform = ($shopId == 1);

            // 对价格记录按时间排序
            ksort($skuData['prices']);
            $priceTimeline = array_values($skuData['prices']);
            $timeKeys = array_keys($skuData['prices']);

            // 对于每个日期，找到该日期结束时的有效价格
            $lastPrice = null;
            $priceIndex = 0;
            $priceCount = count($timeKeys);

            foreach ($dates as $date) {
                $dateEnd = strtotime($date . ' 23:59:59');

                // 更新到该日期结束时的最新价格
                while ($priceIndex < $priceCount && $timeKeys[$priceIndex] <= $dateEnd) {
                    $lastPrice = $priceTimeline[$priceIndex];
                    $priceIndex++;
                }

                // 如果有有效价格，记录到对应位置
                if ($lastPrice !== null && $lastPrice > 0) {
                    if ($isPlatform) {
                        $priceMap[$spec][$date]['platform'] = floatval($lastPrice);
                    } else {
                        $priceMap[$spec][$date]['merchants'][] = floatval($lastPrice);
                    }
                }
            }
        }

        // 构建图表数据
        $chartData = [];
        $summary = [];

        foreach ($specList as $spec) {
            $specData = [
                'spec' => $spec,
                'dates' => $dates,
                'platform_prices' => [],
                'merchant_avg_prices' => [],
                'merchant_min_prices' => [],
                'merchant_max_prices' => [],
                'diff_percentages' => []  // 平台价格与商家均价差异百分比
            ];

            $totalPlatformPrice = 0;
            $totalMerchantAvg = 0;
            $platformCount = 0;
            $merchantCount = 0;

            foreach ($dates as $date) {
                $dayData = $priceMap[$spec][$date];

                // 平台价格
                $platformPrice = $dayData['platform'];
                $specData['platform_prices'][] = $platformPrice;

                if ($platformPrice !== null) {
                    $totalPlatformPrice += $platformPrice;
                    $platformCount++;
                }

                // 商家价格统计
                $merchants = $dayData['merchants'];
                if (!empty($merchants)) {
                    $avg = round(array_sum($merchants) / count($merchants), 2);
                    $min = min($merchants);
                    $max = max($merchants);

                    $specData['merchant_avg_prices'][] = $avg;
                    $specData['merchant_min_prices'][] = $min;
                    $specData['merchant_max_prices'][] = $max;

                    $totalMerchantAvg += $avg;
                    $merchantCount++;

                    // 计算差异百分比
                    if ($platformPrice !== null && $platformPrice > 0) {
                        $diff = round(($platformPrice - $avg) / $avg * 100, 2);
                        $specData['diff_percentages'][] = $diff;
                    } else {
                        $specData['diff_percentages'][] = null;
                    }
                } else {
                    $specData['merchant_avg_prices'][] = null;
                    $specData['merchant_min_prices'][] = null;
                    $specData['merchant_max_prices'][] = null;
                    $specData['diff_percentages'][] = null;
                }
            }

            $chartData[] = $specData;

            // 汇总统计
            $summary[$spec] = [
                'avg_platform_price' => $platformCount > 0 ? round($totalPlatformPrice / $platformCount, 2) : null,
                'avg_merchant_price' => $merchantCount > 0 ? round($totalMerchantAvg / $merchantCount, 2) : null,
                'price_diff' => null,
                'diff_percentage' => null
            ];

            if ($summary[$spec]['avg_platform_price'] !== null && $summary[$spec]['avg_merchant_price'] !== null) {
                $summary[$spec]['price_diff'] = round($summary[$spec]['avg_platform_price'] - $summary[$spec]['avg_merchant_price'], 2);
                if ($summary[$spec]['avg_merchant_price'] > 0) {
                    $summary[$spec]['diff_percentage'] = round($summary[$spec]['price_diff'] / $summary[$spec]['avg_merchant_price'] * 100, 2);
                }
            }
        }

        return [
            'specs' => $specList,
            'chart_data' => $chartData,
            'summary' => $summary
        ];
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
}
