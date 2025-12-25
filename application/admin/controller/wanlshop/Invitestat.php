<?php

namespace app\admin\controller\wanlshop;

use app\common\controller\Backend;
use think\Db;

/**
 * 邀请统计管理
 *
 * @icon fa fa-users
 * @remark 统计用户邀请码、邀请的普通用户和店铺信息
 */
class Invitestat extends Backend
{
    protected $noNeedRight = ['getInviters', 'getInvitedUsers', 'getInvitedShops'];

    /**
     * 主页面
     */
    public function index()
    {
        return $this->view->fetch();
    }

    /**
     * 获取有邀请码的用户列表（邀请人列表）
     */
    public function getInviters()
    {
        $page = $this->request->param('page', 1, 'intval');
        $limit = $this->request->param('limit', 10, 'intval');
        $keyword = $this->request->param('keyword', '', 'trim');

        // 构建基础查询条件
        $baseWhere = function ($query) use ($keyword) {
            $query->whereNotNull('invite_code')
                  ->where('invite_code', '<>', '');
            if ($keyword) {
                $query->where('nickname|mobile|invite_code', 'like', '%' . $keyword . '%');
            }
        };

        // 查询总数
        $total = Db::name('user')
            ->where($baseWhere)
            ->count();

        // 查询列表
        $list = Db::name('user')
            ->where($baseWhere)
            ->field('id, nickname, mobile, avatar, invite_code, createtime, is_salesman')
            ->order('id DESC')
            ->page($page, $limit)
            ->select();

        // 统计每个用户邀请的普通用户数和店铺数
        foreach ($list as &$item) {
            $item['avatar'] = $item['avatar'] ? cdnurl($item['avatar'], true) : '';
            $item['createtime'] = $item['createtime'] ? date('Y-m-d H:i:s', $item['createtime']) : '';

            // 邀请的普通用户数
            $item['invited_user_count'] = Db::name('user')
                ->where('inviter_id', $item['id'])
                ->count();

            // 邀请的店铺数
            $item['invited_shop_count'] = Db::name('wanlshop_shop')
                ->where('inviter_id', $item['id'])
                ->count();
        }
        unset($item);

        $this->success('', null, [
            'total' => $total,
            'list' => $list,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total / $limit)
        ]);
    }

    /**
     * 获取指定用户邀请的普通用户列表
     */
    public function getInvitedUsers()
    {
        $inviterId = $this->request->param('inviter_id', 0, 'intval');
        $page = $this->request->param('page', 1, 'intval');
        $limit = $this->request->param('limit', 10, 'intval');

        if (!$inviterId) {
            $this->error('缺少邀请人ID');
        }

        // 查询总数
        $total = Db::name('user')
            ->where('inviter_id', $inviterId)
            ->count();

        // 查询列表
        $list = Db::name('user')
            ->where('inviter_id', $inviterId)
            ->field('id, nickname, mobile, avatar, createtime, invite_bind_time, bind_shop')
            ->order('id DESC')
            ->page($page, $limit)
            ->select();

        foreach ($list as &$item) {
            $item['avatar'] = $item['avatar'] ? cdnurl($item['avatar'], true) : '';
            $item['createtime'] = $item['createtime'] ? date('Y-m-d H:i:s', $item['createtime']) : '';
            $item['invite_bind_time'] = $item['invite_bind_time'] ? date('Y-m-d H:i:s', $item['invite_bind_time']) : '';

            // 检查是否绑定了店铺
            if ($item['bind_shop']) {
                $shop = Db::name('wanlshop_shop')
                    ->where('id', $item['bind_shop'])
                    ->field('id, shopname, avatar')
                    ->find();
                $item['shop'] = $shop ? [
                    'id' => $shop['id'],
                    'shopname' => $shop['shopname'],
                    'avatar' => $shop['avatar'] ? cdnurl($shop['avatar'], true) : ''
                ] : null;
            } else {
                $item['shop'] = null;
            }
        }
        unset($item);

        $this->success('', null, [
            'total' => $total,
            'list' => $list,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total / $limit)
        ]);
    }

    /**
     * 获取指定用户邀请的店铺列表
     */
    public function getInvitedShops()
    {
        $inviterId = $this->request->param('inviter_id', 0, 'intval');
        $page = $this->request->param('page', 1, 'intval');
        $limit = $this->request->param('limit', 10, 'intval');

        if (!$inviterId) {
            $this->error('缺少邀请人ID');
        }

        // 查询总数
        $total = Db::name('wanlshop_shop')
            ->where('inviter_id', $inviterId)
            ->count();

        // 查询列表
        $list = Db::name('wanlshop_shop')
            ->where('inviter_id', $inviterId)
            ->field('id, user_id, shopname, avatar, city, state, verify, createtime, invite_bind_time')
            ->order('id DESC')
            ->page($page, $limit)
            ->select();

        foreach ($list as &$item) {
            $item['avatar'] = $item['avatar'] ? cdnurl($item['avatar'], true) : '';
            $item['createtime'] = $item['createtime'] ? date('Y-m-d H:i:s', $item['createtime']) : '';
            $item['invite_bind_time'] = $item['invite_bind_time'] ? date('Y-m-d H:i:s', $item['invite_bind_time']) : '';

            // 状态文本
            $stateMap = ['0' => '关闭', '1' => '开启', '2' => '暂停'];
            $item['state_text'] = $stateMap[$item['state']] ?? '未知';

            // 审核状态文本 (0=提交资质,1=提交店铺,2=提交审核,3=通过,4=未通过)
            $verifyMap = ['0' => '提交资质', '1' => '提交店铺', '2' => '提交审核', '3' => '通过', '4' => '未通过'];
            $item['verify_text'] = $verifyMap[$item['verify']] ?? '未知';

            // 获取店铺对应的用户信息
            if ($item['user_id']) {
                $user = Db::name('user')
                    ->where('id', $item['user_id'])
                    ->field('id, nickname, mobile, avatar')
                    ->find();
                $item['user'] = $user ? [
                    'id' => $user['id'],
                    'nickname' => $user['nickname'],
                    'mobile' => $user['mobile'],
                    'avatar' => $user['avatar'] ? cdnurl($user['avatar'], true) : ''
                ] : null;
            } else {
                $item['user'] = null;
            }
        }
        unset($item);

        $this->success('', null, [
            'total' => $total,
            'list' => $list,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total / $limit)
        ]);
    }
}
