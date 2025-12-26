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
    public function commissionLogs()
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
    public function validateCode()
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
}
