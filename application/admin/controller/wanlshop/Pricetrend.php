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
    protected $noNeedRight = ['getCategoryList', 'getSpecList', 'getTrendData', 'getOverviewData'];

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

    /**
     * 获取全品类概览数据 (AJAX)
     * 默认显示的总体统计
     */
    public function getOverviewData()
    {
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
        $categoryStats = Db::name('wanlshop_goods_sku')
            ->alias('sku')
            ->join("{$prefix}wanlshop_goods g", "sku.goods_id = g.id", 'INNER')
            ->join("{$prefix}wanlshop_category c", "c.id = g.category_id", 'INNER')
            ->where('g.deletetime', null)
            ->where('g.status', 'normal')  // 只统计上架商品
            ->where('sku.deletetime', null)
            ->where('sku.state', '0')
            ->where('sku.price', '>', 0)
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
                'COUNT(DISTINCT CASE WHEN g.shop_id != 1 THEN g.shop_id ELSE NULL END) as merchant_shop_count'
            ])
            ->order('c.weigh DESC')
            ->select();

        // 2. 获取按日期聚合的全品类价格趋势（只统计上架商品的SKU更新记录）
        $dailyTrend = Db::name('wanlshop_goods_sku')
            ->alias('sku')
            ->join("{$prefix}wanlshop_goods g", "sku.goods_id = g.id", 'INNER')
            ->where('g.deletetime', null)
            ->where('g.status', 'normal')  // 只统计上架商品
            ->where('sku.deletetime', null)
            ->where('sku.updatetime', 'between', [$startTime, $endTime])
            ->where('sku.price', '>', 0)
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
        $totalStats = Db::name('wanlshop_goods_sku')
            ->alias('sku')
            ->join("{$prefix}wanlshop_goods g", "sku.goods_id = g.id", 'INNER')
            ->where('g.deletetime', null)
            ->where('g.status', 'normal')  // 只统计上架商品
            ->where('sku.deletetime', null)
            ->where('sku.state', '0')
            ->where('sku.price', '>', 0)
            ->field([
                'AVG(CASE WHEN g.shop_id = 1 THEN sku.price ELSE NULL END) as platform_avg',
                'AVG(CASE WHEN g.shop_id != 1 THEN sku.price ELSE NULL END) as merchant_avg',
                'COUNT(DISTINCT g.category_id) as category_count',
                'COUNT(DISTINCT g.id) as goods_count',
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
                'merchant_count' => $totalStats['merchant_count']
            ],
            'category_stats' => $categoryStats,
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
     * 获取指定分类下的规格列表 (AJAX)
     */
    public function getSpecList()
    {
        $categoryId = $this->request->param('category_id', 0, 'intval');

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
            ->where('sku.state', '0')  // 在售SKU
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

    /**
     * 获取价格趋势数据 (AJAX)
     *
     * 这个接口返回价格变动历史，以日期为维度
     */
    public function getTrendData()
    {
        $categoryId = $this->request->param('category_id', 0, 'intval');
        $specs = $this->request->param('specs', '');  // 逗号分隔的规格列表
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
        $categoryName = Db::name('wanlshop_category')
            ->where('id', $categoryId)
            ->value('name');

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

    /**
     * 获取详细数据列表 (AJAX)
     * 用于展示具体每个店铺每个SKU的价格明细
     */
    public function getDetailList()
    {
        $categoryId = $this->request->param('category_id', 0, 'intval');
        $spec = $this->request->param('spec', '');
        $date = $this->request->param('date', '');

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
