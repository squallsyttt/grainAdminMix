<?php

namespace app\admin\model\wanlshop\salesman;

use think\Model;

/**
 * 业务员模型
 */
class Salesman extends Model
{
    // 表名
    protected $name = 'salesman';

    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';

    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    // 追加属性
    protected $append = [
        'status_text'
    ];

    /**
     * 状态列表
     */
    public function getStatusList()
    {
        return [
            'normal' => '正常',
            'disabled' => '禁用'
        ];
    }

    /**
     * 状态文本获取器
     */
    public function getStatusTextAttr($value, $data)
    {
        $value = $value ?: ($data['status'] ?? '');
        $list = $this->getStatusList();
        return $list[$value] ?? '';
    }

    /**
     * 关联：用户
     */
    public function user()
    {
        return $this->belongsTo('\\app\\common\\model\\User', 'user_id', 'id')
            ->field('id, username, nickname, mobile, avatar, inviter_id, bonus_level, bonus_ratio');
    }

    /**
     * 关联：指定管理员
     */
    public function admin()
    {
        return $this->belongsTo('\\app\\admin\\model\\Admin', 'admin_id', 'id')
            ->field('id, username, nickname');
    }

    /**
     * 关联：统计数据
     */
    public function stats()
    {
        return $this->hasOne('SalesmanStats', 'salesman_id', 'id');
    }

    /**
     * 关联：任务进度
     */
    public function taskProgress()
    {
        return $this->hasMany('SalesmanTaskProgress', 'salesman_id', 'id');
    }
}
