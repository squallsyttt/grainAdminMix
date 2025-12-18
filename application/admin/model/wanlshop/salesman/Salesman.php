<?php

namespace app\admin\model\wanlshop\salesman;

use think\Model;

/**
 * 业务员模型（基于 grain_user 表的 is_salesman 字段）
 */
class Salesman extends Model
{
    // 完整表名（包含前缀）
    protected $table = 'grain_user';

    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';

    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    // 追加属性
    protected $append = [
        'salesman_status_text'
    ];

    /**
     * 全局查询范围：只查询业务员
     */
    protected function base($query)
    {
        $query->where('is_salesman', 1);
    }

    /**
     * 状态列表（业务员状态通过 status 字段）
     */
    public function getStatusList()
    {
        return [
            'normal' => '正常',
            'hidden' => '禁用'
        ];
    }

    /**
     * 业务员状态文本获取器
     */
    public function getSalesmanStatusTextAttr($value, $data)
    {
        $status = $data['status'] ?? 'normal';
        $list = $this->getStatusList();
        return $list[$status] ?? '';
    }

    /**
     * 关联：指定管理员
     */
    public function admin()
    {
        return $this->belongsTo('\\app\\admin\\model\\Admin', 'salesman_admin_id', 'id')
            ->field('id, username, nickname');
    }

    /**
     * 关联：统计数据
     */
    public function stats()
    {
        return $this->hasOne('SalesmanStats', 'user_id', 'id');
    }

    /**
     * 关联：任务进度
     */
    public function taskProgress()
    {
        return $this->hasMany('SalesmanTaskProgress', 'user_id', 'id');
    }

    /**
     * 设置用户为业务员
     *
     * @param int $userId 用户ID
     * @param string $remark 备注
     * @param int $adminId 指定管理员ID
     * @return bool
     */
    public static function setSalesman($userId, $remark = '', $adminId = null)
    {
        return self::where('id', $userId)->update([
            'is_salesman' => 1,
            'salesman_remark' => $remark,
            'salesman_admin_id' => $adminId,
            'updatetime' => time()
        ]);
    }

    /**
     * 取消业务员身份
     *
     * @param int $userId 用户ID
     * @return bool
     */
    public static function unsetSalesman($userId)
    {
        return self::where('id', $userId)->update([
            'is_salesman' => 0,
            'salesman_remark' => '',
            'salesman_admin_id' => null,
            'updatetime' => time()
        ]);
    }
}
