<?php

namespace app\api\model\wanlshop;

use think\Model;
use traits\model\SoftDelete;

class GoodsComment extends Model
{
    use SoftDelete;
    
    // 表名
    protected $name = 'wanlshop_goods_comment';
    
    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';

    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $deleteTime = 'deletetime';
    
    // 用户关联
    public function user()
    {
        return $this->belongsTo('app\\api\\model\\wanlshop\\Admin', 'user_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }
}

