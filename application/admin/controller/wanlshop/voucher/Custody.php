<?php

namespace app\admin\controller\wanlshop\voucher;

use app\common\controller\Backend;
use app\admin\model\wanlshop\Voucher;
use app\admin\model\wanlshop\VoucherVerification;
use app\common\service\VoucherRebateService;
use app\admin\service\RebateTransferService;
use app\admin\service\CustodyRefundService;
use think\Db;
use think\Exception;
use think\Log;

/**
 * 核销券代管理审核
 *
 * @icon fa fa-hand-paper-o
 */
class Custody extends Backend
{
    /**
     * Voucher模型对象
     * @var Voucher
     */
    protected $model = null;
    protected $searchFields = 'voucher_no,goods_title';
    protected $relationSearch = true;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new Voucher;

        // 代管理状态筛选
        $this->view->assign("custodyStateList", [
            '0' => '未申请',
            '1' => '申请中',
            '2' => '已通过',
            '3' => '已拒绝',
        ]);
    }

    /**
     * 列表（默认显示申请中）
     */
    public function index()
    {
        $this->relationSearch = true;
        $this->request->filter(['strip_tags', 'trim']);

        if ($this->request->isAjax()) {
            if ($this->request->request('keyField')) {
                return $this->selectpage();
            }

            list($where, $sort, $order, $offset, $limit) = $this->buildparams();

            // 默认只显示代管理相关的券（custody_state != 0）
            $custodyState = $this->request->get('custody_state', '1');

            $total = $this->model
                ->with(['user', 'voucherOrder', 'voucherRule'])
                ->where($where)
                ->where('custody_state', '<>', '0')
                ->order($sort, $order)
                ->count();

            $list = $this->model
                ->with(['user', 'voucherOrder', 'voucherRule'])
                ->where($where)
                ->where('custody_state', '<>', '0')
                ->order($sort, $order)
                ->limit($offset, $limit)
                ->select();

            foreach ($list as $row) {
                if ($row->user) {
                    $row->getRelation('user')->visible(['nickname', 'mobile', 'avatar']);
                }
            }

            $list = collection($list)->toArray();
            $result = array("total" => $total, "rows" => $list);

            return json($result);
        }
        return $this->view->fetch();
    }

    /**
     * 详情
     */
    public function detail($ids = null)
    {
        $row = $this->model
            ->with(['user', 'voucherOrder', 'voucherRule', 'goods'])
            ->find($ids);

        if (!$row) {
            $this->error(__('No Results were found'));
        }

        // 获取库存对比分析数据
        $inventoryAnalysis = $this->getInventoryAnalysis($row);

        if ($this->request->isAjax()) {
            $this->success('ok', ['row' => $row, 'inventory' => $inventoryAnalysis]);
        }

        $this->view->assign("row", $row);
        $this->view->assign("inventory", $inventoryAnalysis);
        return $this->view->fetch();
    }

    /**
     * 获取库存对比分析数据
     *
     * 计算当前券的SKU与平台同城市、同品类的所有待使用券的库存占比
     *
     * @param Voucher $voucher 当前券
     * @return array
     */
    protected function getInventoryAnalysis($voucher)
    {
        // 获取券关联的商品城市信息
        $goods = $voucher->goods;
        if (!$goods) {
            return ['error' => '商品信息不存在'];
        }

        $categoryId = $voucher->category_id;
        $regionCityCode = $goods->region_city_code;
        $regionCityName = $goods->region_city_name ?: '未知城市';
        $currentSkuDifference = $voucher->sku_difference;
        $currentSkuWeight = (float)$voucher->sku_weight;

        // 查询同城市、同品类的所有待使用券按SKU分组统计
        $skuStats = Db::name('wanlshop_voucher')
            ->alias('v')
            ->join('wanlshop_goods g', 'v.goods_id = g.id', 'LEFT')
            ->where('v.state', 1)  // 待使用
            ->where('v.status', 'normal')
            ->where('v.category_id', $categoryId)
            ->where('g.region_city_code', $regionCityCode)
            ->group('v.sku_difference')
            ->field([
                'v.sku_difference',
                'COUNT(*) as voucher_count',
                'SUM(v.sku_weight) as total_weight'
            ])
            ->select();

        // 计算总重量
        $totalWeight = 0;
        $totalCount = 0;
        $skuList = [];

        foreach ($skuStats as $stat) {
            $weight = (float)$stat['total_weight'];
            $count = (int)$stat['voucher_count'];
            $totalWeight += $weight;
            $totalCount += $count;

            $skuList[] = [
                'sku_difference' => $stat['sku_difference'] ?: '未知规格',
                'voucher_count' => $count,
                'total_weight' => $weight,
                'is_current' => ($stat['sku_difference'] == $currentSkuDifference),
            ];
        }

        // 计算每个SKU的占比
        foreach ($skuList as &$sku) {
            $sku['weight_ratio'] = $totalWeight > 0 ? round($sku['total_weight'] / $totalWeight * 100, 2) : 0;
            $sku['count_ratio'] = $totalCount > 0 ? round($sku['voucher_count'] / $totalCount * 100, 2) : 0;
        }
        unset($sku);

        // 按重量降序排列
        usort($skuList, function ($a, $b) {
            return $b['total_weight'] <=> $a['total_weight'];
        });

        // 当前券的SKU统计
        $currentSkuStat = null;
        foreach ($skuList as $sku) {
            if ($sku['is_current']) {
                $currentSkuStat = $sku;
                break;
            }
        }

        return [
            'region_city_code' => $regionCityCode,
            'region_city_name' => $regionCityName,
            'category_id' => $categoryId,
            'current_sku' => [
                'sku_difference' => $currentSkuDifference,
                'sku_weight' => $currentSkuWeight,
                'voucher_count' => $currentSkuStat ? $currentSkuStat['voucher_count'] : 0,
                'total_weight' => $currentSkuStat ? $currentSkuStat['total_weight'] : 0,
                'weight_ratio' => $currentSkuStat ? $currentSkuStat['weight_ratio'] : 0,
                'count_ratio' => $currentSkuStat ? $currentSkuStat['count_ratio'] : 0,
            ],
            'total' => [
                'voucher_count' => $totalCount,
                'total_weight' => $totalWeight,
            ],
            'sku_list' => $skuList,
        ];
    }

    /**
     * 审核通过
     */
    public function approve()
    {
        $ids = $this->request->post('ids');
        if (!$ids) {
            $this->error(__('Parameter %s can not be empty', 'ids'));
        }

        $ids = is_array($ids) ? $ids : explode(',', $ids);

        Db::startTrans();
        try {
            $successCount = 0;
            $failedCount = 0;
            $errors = [];

            foreach ($ids as $id) {
                try {
                    $this->processApprove((int)$id);
                    $successCount++;
                } catch (Exception $e) {
                    $failedCount++;
                    $errors[] = "券ID {$id}: " . $e->getMessage();
                }
            }

            Db::commit();

            if ($failedCount > 0) {
                $this->error("成功 {$successCount} 条，失败 {$failedCount} 条: " . implode('; ', $errors));
            }

            $this->success("审核通过 {$successCount} 条");

        } catch (Exception $e) {
            Db::rollback();
            $this->error('审核失败：' . $e->getMessage());
        }
    }

    /**
     * 审核拒绝
     */
    public function reject()
    {
        $ids = $this->request->post('ids');
        $refuseReason = $this->request->post('refuse_reason', '');

        if (!$ids) {
            $this->error(__('Parameter %s can not be empty', 'ids'));
        }

        if (!$refuseReason) {
            $this->error(__('请填写拒绝理由'));
        }

        $ids = is_array($ids) ? $ids : explode(',', $ids);

        Db::startTrans();
        try {
            $successCount = 0;
            $failedCount = 0;
            $errors = [];

            foreach ($ids as $id) {
                try {
                    $this->processReject((int)$id, $refuseReason);
                    $successCount++;
                } catch (Exception $e) {
                    $failedCount++;
                    $errors[] = "券ID {$id}: " . $e->getMessage();
                }
            }

            Db::commit();

            if ($failedCount > 0) {
                $this->error("成功 {$successCount} 条，失败 {$failedCount} 条: " . implode('; ', $errors));
            }

            $this->success("审核拒绝 {$successCount} 条");

        } catch (Exception $e) {
            Db::rollback();
            $this->error('审核失败：' . $e->getMessage());
        }
    }

    /**
     * 统计数据
     */
    public function statistics()
    {
        $stats = Db::name('wanlshop_voucher')
            ->where('status', 'normal')
            ->field("
                SUM(CASE WHEN custody_state = '1' THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN custody_state = '2' THEN 1 ELSE 0 END) AS approved,
                SUM(CASE WHEN custody_state = '3' THEN 1 ELSE 0 END) AS rejected
            ")
            ->find();

        $this->success('ok', [
            'pending' => (int)($stats['pending'] ?? 0),
            'approved' => (int)($stats['approved'] ?? 0),
            'rejected' => (int)($stats['rejected'] ?? 0),
        ]);
    }

    /**
     * 处理单个审核通过
     *
     * 代管理审核通过后执行：
     * 1. 更新券状态为已核销
     * 2. 创建虚拟核销记录
     * 3. 创建返利记录（按审核通过时间计算返利阶段和等量退款）
     * 4. 自动发起返利打款（无需等待7天）
     * 5. 自动发起等量退款（按实际货物存量退款）
     *
     * @param int $voucherId
     * @throws Exception
     */
    protected function processApprove($voucherId)
    {
        // 查询券并加锁
        $voucher = Voucher::where('id', $voucherId)
            ->lock(true)
            ->find();

        if (!$voucher) {
            throw new Exception('券不存在');
        }

        // 验证券状态
        if ($voucher->state != 1) {
            throw new Exception('券状态不是未使用');
        }

        // 验证代管理状态
        if ($voucher->custody_state != '1') {
            throw new Exception('券不是申请中状态');
        }

        // 审核通过时间作为返利计算基准
        $approveTime = time();

        // 获取平台店铺商品信息（shop_id=1）
        $rebateService = new VoucherRebateService();
        $platformGoodsInfo = $rebateService->getShopGoodsInfo(
            1,  // 平台店铺 ID
            $voucher->category_id,
            $voucher->sku_difference
        );
        $platformSupplyPrice = $platformGoodsInfo['sku_price'];

        // 更新券状态
        $voucher->state = 2;  // 已核销
        $voucher->custody_state = '2';  // 已通过
        $voucher->custody_audit_time = $approveTime;
        $voucher->custody_admin_id = $this->auth->id;
        $voucher->verifytime = $approveTime;  // 核销时间 = 审核通过时间
        $voucher->shop_id = 1;  // 平台店铺
        $voucher->shop_name = '平台代管理';
        $voucher->supply_price = $platformSupplyPrice;
        $voucher->verify_user_id = $this->auth->id;
        $voucher->save();

        // 创建虚拟核销记录（核销时间为审核通过时间）
        $verification = new VoucherVerification();
        $verification->voucher_id = $voucher->id;
        $verification->voucher_no = $voucher->voucher_no;
        $verification->user_id = $voucher->user_id;
        $verification->shop_id = 1;  // 平台店铺
        $verification->shop_name = '平台代管理';
        $verification->verify_user_id = $this->auth->id;
        $verification->shop_goods_id = $platformGoodsInfo['goods_id'];
        $verification->shop_goods_title = $platformGoodsInfo['goods_title'];
        $verification->supply_price = $platformSupplyPrice;
        $verification->face_value = $voucher->face_value;
        $verification->verify_method = 'custody';  // 代管理核销方式
        $verification->createtime = $approveTime;  // 核销时间 = 审核通过时间
        $verification->save();

        // 创建返利记录（使用代管理返利类型，按审核通过时间计算）
        $rebate = $rebateService->createRebateRecord(
            $voucher,
            $verification,
            $approveTime,  // 审核通过时间作为计算基准
            1,
            $platformGoodsInfo,
            'custody'
        );

        Log::info("代管理审核通过[voucher_id={$voucher->id}]: 返利金额={$rebate->rebate_amount}, 等量退款={$rebate->refund_amount}, 阶段={$rebate->stage}");

        // 自动发起返利打款（代管理不需要等待7天）
        if ($rebate->rebate_amount > 0) {
            try {
                $transferService = new RebateTransferService();
                $transferResult = $transferService->transferCustody((int)$rebate->id);

                if (!$transferResult['success']) {
                    Log::warning("代管理返利打款发起失败[rebate_id={$rebate->id}]: " . ($transferResult['message'] ?? '未知错误'));
                    // 打款失败不影响审核通过流程，记录日志即可
                }
            } catch (\Exception $e) {
                Log::error("代管理返利打款异常[rebate_id={$rebate->id}]: " . $e->getMessage());
                // 打款异常不影响审核通过流程
            }
        } else {
            Log::info("代管理返利金额为0[rebate_id={$rebate->id}]，无需打款");
        }

        // 自动发起等量退款（代管理独有功能）
        if ($rebate->refund_amount > 0) {
            try {
                $custodyRefundService = new CustodyRefundService();
                $refundResult = $custodyRefundService->createCustodyRefund($rebate, $voucher);

                if (!$refundResult['success']) {
                    Log::warning("代管理等量退款发起失败[rebate_id={$rebate->id}]: " . ($refundResult['message'] ?? '未知错误'));
                    // 退款失败不影响审核通过流程，记录日志即可
                } else {
                    Log::info("代管理等量退款发起成功[rebate_id={$rebate->id}]: 退款金额={$rebate->refund_amount}");
                }
            } catch (\Exception $e) {
                Log::error("代管理等量退款异常[rebate_id={$rebate->id}]: " . $e->getMessage());
                // 退款异常不影响审核通过流程
            }
        } else {
            Log::info("代管理等量退款金额为0[rebate_id={$rebate->id}]，无需退款（可能已过期）");
        }
    }

    /**
     * 处理单个审核拒绝
     *
     * @param int $voucherId
     * @param string $refuseReason
     * @throws Exception
     */
    protected function processReject($voucherId, $refuseReason)
    {
        // 查询券并加锁
        $voucher = Voucher::where('id', $voucherId)
            ->lock(true)
            ->find();

        if (!$voucher) {
            throw new Exception('券不存在');
        }

        // 验证代管理状态
        if ($voucher->custody_state != '1') {
            throw new Exception('券不是申请中状态');
        }

        $now = time();

        // 更新代管理状态为已拒绝
        $voucher->custody_state = '3';  // 已拒绝
        $voucher->custody_audit_time = $now;
        $voucher->custody_admin_id = $this->auth->id;
        $voucher->custody_refuse_reason = $refuseReason;
        // state 保持不变（用户可继续核销或重新申请）
        $voucher->save();
    }
}
