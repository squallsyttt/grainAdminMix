<?php
/**
 * BD推广员后台管理
 *
 * @icon fa fa-user-secret
 */

namespace app\admin\controller\wanlshop;

use app\common\controller\Backend;
use app\common\service\BdPromoterService;
use app\admin\service\RebateTransferService;
use think\Db;

class Bdpromoter extends Backend
{
    /**
     * 无需鉴权的方法
     */
    protected $noNeedRight = ['detail', 'stats', 'transfer'];

    public function _initialize()
    {
        parent::_initialize();
    }

    /**
     * BD推广员列表
     */
    public function index()
    {
        $this->request->filter(['strip_tags', 'trim']);

        if ($this->request->isAjax()) {
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();

            // 搜索条件
            $search = $this->request->get('search', '');

            // 构建基础条件闭包
            $buildBaseWhere = function ($query) use ($search) {
                $query->where('bd_code', 'not null')
                      ->where('bd_code', '<>', '');
                if ($search) {
                    $query->where(function ($q) use ($search) {
                        $q->where('nickname', 'like', "%{$search}%")
                          ->whereOr('bd_code', 'like', "%{$search}%")
                          ->whereOr('mobile', 'like', "%{$search}%");
                    });
                }
            };

            // 计数查询
            $total = Db::name('user')->where($buildBaseWhere)->count();

            // 列表查询
            $list = Db::name('user')
                ->where($buildBaseWhere)
                ->field('id, nickname, avatar, mobile, bd_code, bd_apply_time')
                ->order($sort ?: 'bd_apply_time', $order ?: 'desc')
                ->limit($offset, $limit)
                ->select();

            // 补充统计数据
            foreach ($list as &$row) {
                // 邀请店铺数
                $row['shop_count'] = Db::name('bd_shop_bindlog')
                    ->where('bd_user_id', $row['id'])
                    ->count();

                // 累计佣金
                $row['total_commission'] = Db::name('bd_commission_log')
                    ->where('bd_user_id', $row['id'])
                    ->where('type', 'earn')
                    ->where('settle_status', '<>', 'cancelled')
                    ->sum('commission_amount') ?: 0;

                // 当前周期信息
                $period = Db::name('bd_promoter_period')
                    ->where('user_id', $row['id'])
                    ->where('status', 'active')
                    ->order('period_index', 'desc')
                    ->find();

                $row['current_rate'] = $period ? (float)$period['current_rate'] : 0;
                $row['period_index'] = $period ? (int)$period['period_index'] : 0;
                $row['period_shop_count'] = $period ? (int)$period['shop_count'] : 0;

                // 格式化时间
                $row['bd_apply_time_text'] = $row['bd_apply_time'] ? date('Y-m-d H:i:s', $row['bd_apply_time']) : '-';
                $row['current_rate_text'] = $row['current_rate'] > 0 ? ($row['current_rate'] * 1000) . '‰' : '未激活';
            }
            unset($row);

            $result = ['total' => $total, 'rows' => $list];
            return json($result);
        }

        return $this->view->fetch();
    }

    /**
     * BD推广员详情
     */
    public function detail($ids = null)
    {
        $id = $ids ?: $this->request->get('ids');
        if (!$id) {
            $this->error(__('参数错误'));
        }

        $bdService = new BdPromoterService();
        $info = $bdService->getBdPromoterInfo($id);

        if (!$info['is_bd_promoter']) {
            $this->error(__('该用户不是BD推广员'));
        }

        // 获取用户基本信息
        $user = Db::name('user')
            ->where('id', $id)
            ->field('id, nickname, avatar, mobile, bd_code, bd_apply_time')
            ->find();

        // 获取周期列表
        $periods = $bdService->getPeriods($id);

        // 获取邀请店铺列表
        $shops = $bdService->getInvitedShops($id, 1, 100);

        // 获取佣金明细（最近50条）
        $commissions = $bdService->getCommissionLogs($id, 1, 50);

        $this->view->assign([
            'user' => $user,
            'info' => $info,
            'periods' => $periods,
            'shops' => $shops['list'],
            'commissions' => $commissions['list']
        ]);

        return $this->view->fetch();
    }

    /**
     * BD统计报表
     */
    public function stats()
    {
        if ($this->request->isAjax()) {
            $type = $this->request->get('type', 'today');

            // 计算时间范围
            $now = time();
            switch ($type) {
                case 'today':
                    $startTime = strtotime(date('Y-m-d'));
                    $endTime = $now;
                    break;
                case '3days':
                    $startTime = strtotime('-2 days', strtotime(date('Y-m-d')));
                    $endTime = $now;
                    break;
                case '7days':
                    $startTime = strtotime('-6 days', strtotime(date('Y-m-d')));
                    $endTime = $now;
                    break;
                case '30days':
                    $startTime = strtotime('-29 days', strtotime(date('Y-m-d')));
                    $endTime = $now;
                    break;
                default:
                    $startTime = $this->request->get('start_time/d', strtotime(date('Y-m-d')));
                    $endTime = $this->request->get('end_time/d', $now);
            }

            // 统计数据
            $stats = [
                // 新增BD数
                'new_bd_count' => Db::name('user')
                    ->where('bd_apply_time', '>=', $startTime)
                    ->where('bd_apply_time', '<=', $endTime)
                    ->where('bd_code', 'not null')
                    ->count(),

                // 新增店铺绑定数
                'new_shop_bind_count' => Db::name('bd_shop_bindlog')
                    ->where('createtime', '>=', $startTime)
                    ->where('createtime', '<=', $endTime)
                    ->count(),

                // 总佣金
                'total_commission' => Db::name('bd_commission_log')
                    ->where('createtime', '>=', $startTime)
                    ->where('createtime', '<=', $endTime)
                    ->where('type', 'earn')
                    ->where('settle_status', '<>', 'cancelled')
                    ->sum('commission_amount') ?: 0,

                // 扣减佣金
                'deduct_commission' => Db::name('bd_commission_log')
                    ->where('createtime', '>=', $startTime)
                    ->where('createtime', '<=', $endTime)
                    ->where('type', 'deduct')
                    ->sum('commission_amount') ?: 0,

                // 活跃BD数（本时段有佣金收入的BD）
                'active_bd_count' => Db::name('bd_commission_log')
                    ->where('createtime', '>=', $startTime)
                    ->where('createtime', '<=', $endTime)
                    ->where('type', 'earn')
                    ->group('bd_user_id')
                    ->count(),
            ];

            // 净佣金
            $stats['net_commission'] = round($stats['total_commission'] - $stats['deduct_commission'], 2);

            // BD排行榜（按佣金排序）
            $topBdList = Db::name('bd_commission_log')
                ->alias('c')
                ->join('user u', 'u.id = c.bd_user_id', 'LEFT')
                ->where('c.createtime', '>=', $startTime)
                ->where('c.createtime', '<=', $endTime)
                ->where('c.type', 'earn')
                ->where('c.settle_status', '<>', 'cancelled')
                ->field('c.bd_user_id, u.nickname, u.bd_code, SUM(c.commission_amount) as total_commission')
                ->group('c.bd_user_id')
                ->order('total_commission', 'desc')
                ->limit(10)
                ->select();

            $stats['top_bd_list'] = $topBdList;

            $this->success('ok', $stats);
        }

        return $this->view->fetch();
    }

    /**
     * BD佣金打款
     *
     * @param int $commission_log_id 佣金明细ID
     */
    public function transfer()
    {
        if (!$this->request->isPost()) {
            $this->error(__('请求方式错误'));
        }

        $commissionLogId = $this->request->post('commission_log_id/d');
        if ($commissionLogId <= 0) {
            $this->error(__('参数错误'));
        }

        $bdService = new BdPromoterService();

        // 1. 创建返利记录（后台管理员可跳过时间限制检查）
        $createResult = $bdService->createRebateRecord($commissionLogId, true);
        if (!$createResult['success']) {
            $this->error($createResult['message']);
        }

        $rebateId = $createResult['rebate_id'];

        // 2. 执行打款
        $transferService = new RebateTransferService();
        $transferResult = $transferService->transferBdCommission($rebateId);

        if (!$transferResult['success']) {
            $this->error($transferResult['message']);
        }

        // 返回打款结果
        $data = $transferResult['data'];
        $message = '打款请求已发送';
        if ($data['need_user_confirm']) {
            $message = '打款已发起，等待用户确认收款';
        } elseif ($data['transfer_state'] === 'SUCCESS') {
            $message = '打款成功';
        }

        $this->success($message, $data);
    }

    /**
     * 批量BD佣金打款
     *
     * @param int $bd_user_id BD推广员用户ID
     */
    public function batchTransfer()
    {
        if (!$this->request->isPost()) {
            $this->error(__('请求方式错误'));
        }

        $bdUserId = $this->request->post('bd_user_id/d');
        if ($bdUserId <= 0) {
            $this->error(__('参数错误'));
        }

        $bdService = new BdPromoterService();
        $transferService = new RebateTransferService();

        // 创建所有可打款的返利记录
        $createResult = $bdService->batchCreateRebateRecords($bdUserId);

        $successCount = 0;
        $failedCount = 0;
        $errors = [];

        // 获取所有待打款的返利记录
        $rebates = Db::name('wanlshop_voucher_rebate')
            ->where('rebate_type', 'bd_promoter')
            ->where('user_id', $bdUserId)
            ->where('payment_status', 'unpaid')
            ->select();

        foreach ($rebates as $rebate) {
            $transferResult = $transferService->transferBdCommission((int)$rebate['id']);
            if ($transferResult['success']) {
                $successCount++;
            } else {
                $failedCount++;
                $errors[] = [
                    'rebate_id' => $rebate['id'],
                    'message' => $transferResult['message']
                ];
            }
        }

        if ($successCount > 0) {
            $this->success("批量打款完成：成功 {$successCount} 笔，失败 {$failedCount} 笔", [
                'success_count' => $successCount,
                'failed_count' => $failedCount,
                'errors' => $errors
            ]);
        } else {
            $this->error('批量打款失败：' . ($errors[0]['message'] ?? '无可打款记录'));
        }
    }
}
