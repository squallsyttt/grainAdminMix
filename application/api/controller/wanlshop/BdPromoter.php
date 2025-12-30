<?php
/**
 * BD推广员API接口
 *
 * 提供BD推广员相关的前端API接口
 */

namespace app\api\controller\wanlshop;

use app\common\controller\Api;
use app\common\service\BdPromoterService;
use think\Exception;

class BdPromoter extends Api
{
    protected $noNeedLogin = [];
    protected $noNeedRight = ['*'];

    /**
     * BD推广员服务实例
     * @var BdPromoterService
     */
    protected $bdService;

    public function _initialize()
    {
        parent::_initialize();
        $this->bdService = new BdPromoterService();
    }

    /**
     * 获取当前用户BD信息
     *
     * @ApiSummary (获取当前用户BD推广员信息)
     * @ApiMethod  (GET)
     * @ApiReturn  ({
     *   "code": 1,
     *   "msg": "success",
     *   "data": {
     *     "is_bd_promoter": true,
     *     "bd_code": "BD12345678ABCD",
     *     "apply_time": 1703520000,
     *     "current_period_index": 1,
     *     "current_rate": 0.001,
     *     "is_active": true,
     *     "current_period_shop_count": 2,
     *     "total_shop_count": 5,
     *     "total_commission": 12.50,
     *     "pending_commission": 10.00
     *   }
     * })
     */
    public function info()
    {
        try {
            $info = $this->bdService->getBdPromoterInfo($this->auth->id);
            $this->success('ok', $info);
        } catch (Exception $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 申请成为BD推广员
     *
     * @ApiSummary (申请成为BD推广员)
     * @ApiMethod  (POST)
     * @ApiReturn  ({
     *   "code": 1,
     *   "msg": "申请成功",
     *   "data": {
     *     "bd_code": "BD12345678ABCD",
     *     "period_id": 1
     *   }
     * })
     */
    public function apply()
    {
        if (!$this->request->isPost()) {
            $this->error(__('非法请求'));
        }

        try {
            $result = $this->bdService->applyBdPromoter($this->auth->id);
            $this->success('申请成功', $result);
        } catch (Exception $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取邀请的店铺列表
     *
     * @ApiSummary (获取BD邀请的店铺列表)
     * @ApiMethod  (GET)
     * @ApiParams  (name="page", type="int", required=false, description="页码，默认1")
     * @ApiParams  (name="limit", type="int", required=false, description="每页数量，默认10")
     * @ApiReturn  ({
     *   "code": 1,
     *   "msg": "ok",
     *   "data": {
     *     "list": [
     *       {
     *         "id": 1,
     *         "shop_id": 10,
     *         "shopname": "米店A",
     *         "avatar": "http://...",
     *         "city": "深圳",
     *         "bind_time": 1703520000,
     *         "period_index": 1
     *       }
     *     ],
     *     "total": 5
     *   }
     * })
     */
    public function shops()
    {
        $page = $this->request->get('page/d', 1);
        $limit = $this->request->get('limit/d', 10);

        if ($page < 1) $page = 1;
        if ($limit < 1 || $limit > 50) $limit = 10;

        try {
            $result = $this->bdService->getInvitedShops($this->auth->id, $page, $limit);
            $this->success('ok', $result);
        } catch (Exception $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取各周期统计
     *
     * @ApiSummary (获取BD各周期统计数据)
     * @ApiMethod  (GET)
     * @ApiReturn  ({
     *   "code": 1,
     *   "msg": "ok",
     *   "data": [
     *     {
     *       "id": 1,
     *       "period_index": 1,
     *       "period_start": 1703520000,
     *       "period_end": 1711296000,
     *       "shop_count": 3,
     *       "prev_rate": 0.000,
     *       "current_rate": 0.003,
     *       "total_commission": 25.50,
     *       "status": "active"
     *     }
     *   ]
     * })
     */
    public function periods()
    {
        try {
            $result = $this->bdService->getPeriods($this->auth->id);
            $this->success('ok', $result);
        } catch (Exception $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取佣金明细
     *
     * @ApiSummary (获取BD佣金明细列表)
     * @ApiMethod  (GET)
     * @ApiParams  (name="page", type="int", required=false, description="页码，默认1")
     * @ApiParams  (name="limit", type="int", required=false, description="每页数量，默认10")
     * @ApiParams  (name="start_time", type="int", required=false, description="开始时间戳")
     * @ApiParams  (name="end_time", type="int", required=false, description="结束时间戳")
     * @ApiReturn  ({
     *   "code": 1,
     *   "msg": "ok",
     *   "data": {
     *     "list": [
     *       {
     *         "id": 1,
     *         "shop_id": 10,
     *         "shop_name": "米店A",
     *         "type": "earn",
     *         "order_amount": 80.00,
     *         "commission_rate": 0.002,
     *         "commission_amount": 0.16,
     *         "settle_status": "pending",
     *         "createtime": 1703520000
     *       }
     *     ],
     *     "total": 20
     *   }
     * })
     */
    public function commission_logs()
    {
        $page = $this->request->get('page/d', 1);
        $limit = $this->request->get('limit/d', 10);
        $startTime = $this->request->get('start_time/d', null);
        $endTime = $this->request->get('end_time/d', null);

        if ($page < 1) $page = 1;
        if ($limit < 1 || $limit > 50) $limit = 10;

        try {
            $result = $this->bdService->getCommissionLogs($this->auth->id, $page, $limit, $startTime, $endTime);
            $this->success('ok', $result);
        } catch (Exception $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 验证BD码是否有效
     *
     * @ApiSummary (验证BD推广码是否有效)
     * @ApiMethod  (GET)
     * @ApiParams  (name="bd_code", type="string", required=true, description="BD推广码")
     * @ApiReturn  ({
     *   "code": 1,
     *   "msg": "ok",
     *   "data": {
     *     "valid": true,
     *     "bd_user": {
     *       "id": 1,
     *       "nickname": "推广员A",
     *       "avatar": "http://..."
     *     }
     *   }
     * })
     */
    public function validate_code()
    {
        $bdCode = $this->request->get('bd_code', '');

        if (empty($bdCode)) {
            $this->error('请输入BD推广码');
        }

        $bdUser = $this->bdService->validateBdCode($bdCode, $this->auth->id);

        if ($bdUser) {
            $this->success('ok', [
                'valid' => true,
                'bd_user' => $bdUser
            ]);
        } else {
            $this->success('ok', [
                'valid' => false,
                'bd_user' => null
            ]);
        }
    }

    /**
     * 获取店铺选项列表（用于筛选下拉）
     *
     * @ApiSummary (获取BD邀请的店铺选项列表)
     * @ApiMethod  (GET)
     * @ApiReturn  ({
     *   "code": 1,
     *   "msg": "ok",
     *   "data": [
     *     {"id": 1, "name": "店铺A"},
     *     {"id": 2, "name": "店铺B"}
     *   ]
     * })
     */
    public function shop_options()
    {
        try {
            $result = $this->bdService->getInvitedShops($this->auth->id, 1, 100);
            $options = [];
            if ($result && !empty($result['list'])) {
                foreach ($result['list'] as $shop) {
                    $options[] = [
                        'id' => $shop['shop_id'],
                        'name' => $shop['shopname']
                    ];
                }
            }
            $this->success('ok', $options);
        } catch (Exception $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取流水明细统计
     *
     * @ApiSummary (获取BD流水明细统计)
     * @ApiMethod  (GET)
     * @ApiParams  (name="shop_id", type="int", required=false, description="店铺ID筛选")
     * @ApiParams  (name="start_date", type="string", required=false, description="开始日期，如2025-01-01")
     * @ApiParams  (name="end_date", type="string", required=false, description="结束日期，如2025-01-31")
     * @ApiReturn  ({
     *   "code": 1,
     *   "msg": "ok",
     *   "data": {
     *     "list": [],
     *     "summary": {"earn_amount": 100, "deduct_amount": 10, "net_amount": 90},
     *     "filter": {"shop_id": 0, "start_date": "2025-01-01", "end_date": "2025-01-31"}
     *   }
     * })
     */
    public function settlement_data()
    {
        $shopId = $this->request->get('shop_id/d', 0);
        $startDate = $this->request->get('start_date', '');
        $endDate = $this->request->get('end_date', '');

        // 时间范围
        $startTime = $startDate ? strtotime($startDate . ' 00:00:00') : strtotime(date('Y-m-01'));
        $endTime = $endDate ? strtotime($endDate . ' 23:59:59') : time();

        try {
            $bdUserId = $this->auth->id;

            // 构建查询条件
            $where = ['c.bd_user_id' => $bdUserId];
            if ($shopId > 0) {
                $where['c.shop_id'] = $shopId;
            }

            // 获取流水明细
            $list = \think\Db::name('bd_commission_log')
                ->alias('c')
                ->join('wanlshop_shop s', 's.id = c.shop_id', 'LEFT')
                ->join('bd_promoter_period p', 'p.id = c.period_id', 'LEFT')
                ->where($where)
                ->where('c.createtime', '>=', $startTime)
                ->where('c.createtime', '<=', $endTime)
                ->field('c.*, s.shopname, p.period_index, p.current_rate as period_rate')
                ->order('c.createtime', 'desc')
                ->select();

            // 统计汇总
            $summary = [
                'earn_count' => 0,
                'earn_amount' => 0,
                'deduct_count' => 0,
                'deduct_amount' => 0,
                'net_amount' => 0,
                'settled_amount' => 0,
                'pending_amount' => 0,
            ];

            foreach ($list as &$row) {
                $row['createtime_text'] = date('Y-m-d H:i:s', $row['createtime']);

                // 类型描述
                if ($row['type'] === 'earn') {
                    $row['type_text'] = '收入';
                    $row['type_desc'] = '核销返佣';
                } else {
                    $row['type_text'] = '扣减';
                    $row['type_desc'] = $row['refund_id'] ? '订单退款' : '其他扣减';
                }

                // 结算状态
                $row['settle_status_text'] = $this->getSettleStatusText($row['settle_status']);

                // 周期信息
                $row['period_index'] = $row['period_index'] ?: '-';
                $row['period_index_text'] = $row['period_index'] !== '-' ? '第' . $row['period_index'] . '周期' : '-';

                // 比例
                $rate = (float)$row['commission_rate'];
                $row['rate_text'] = $rate > 0 ? ($rate * 1000) . '‰' : '-';

                // 计算公式
                $orderAmount = (float)$row['order_amount'];
                $commissionAmount = (float)$row['commission_amount'];
                if ($orderAmount > 0 && $rate > 0) {
                    $row['formula'] = '¥' . number_format($orderAmount, 2) . ' × ' . ($rate * 1000) . '‰ = ¥' . number_format($commissionAmount, 2);
                } else {
                    $row['formula'] = '¥' . number_format($commissionAmount, 2);
                }

                // 汇总统计
                if ($row['type'] === 'earn') {
                    $summary['earn_count']++;
                    $summary['earn_amount'] += $commissionAmount;
                    if ($row['settle_status'] === 'settled') {
                        $summary['settled_amount'] += $commissionAmount;
                    } elseif ($row['settle_status'] !== 'cancelled') {
                        $summary['pending_amount'] += $commissionAmount;
                    }
                } else {
                    $summary['deduct_count']++;
                    $summary['deduct_amount'] += $commissionAmount;
                }
            }
            unset($row);

            $summary['net_amount'] = round($summary['earn_amount'] - $summary['deduct_amount'], 2);
            $summary['earn_amount'] = round($summary['earn_amount'], 2);
            $summary['deduct_amount'] = round($summary['deduct_amount'], 2);
            $summary['settled_amount'] = round($summary['settled_amount'], 2);
            $summary['pending_amount'] = round($summary['pending_amount'], 2);

            $this->success('ok', [
                'list' => $list,
                'summary' => $summary,
                'filter' => [
                    'bd_user_id' => $bdUserId,
                    'shop_id' => $shopId,
                    'start_date' => date('Y-m-d', $startTime),
                    'end_date' => date('Y-m-d', $endTime),
                ]
            ]);
        } catch (Exception $e) {
            $this->error($e->getMessage());
        }
    }

    /**
     * 获取结算状态文本
     */
    private function getSettleStatusText($status)
    {
        $map = [
            'pending' => '待结算',
            'settling' => '结算中',
            'settled' => '已结算',
            'cancelled' => '已取消',
        ];
        return $map[$status] ?? $status;
    }
}
