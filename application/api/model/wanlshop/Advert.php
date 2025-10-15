<?php

namespace app\api\model\wanlshop;

use think\Model;
use traits\model\SoftDelete;

/**
 * 广告模型 - API专用
 *
 * 用于前端展示广告数据,只暴露必要字段
 */
class Advert extends Model
{
    use SoftDelete;

    // 表名(完整表名: grain_wanlshop_advert)
    protected $name = 'wanlshop_advert';

    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';

    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $deleteTime = 'deletetime';

    // 追加属性(用于API输出)
    protected $append = [
        'module_text',
        'type_text'
    ];

    /**
     * 获取模块列表
     * @return array
     */
    public function getModuleList()
    {
        return [
            'open' => '开屏广告',
            'page' => '页面轮播',
            'category' => '分类页',
            'first' => '首页推荐',
            'other' => '其他位置'
        ];
    }

    /**
     * 获取类型列表
     * @return array
     */
    public function getTypeList()
    {
        return [
            'banner' => '横幅图',
            'image' => '方形图',
            'video' => '视频'
        ];
    }

    /**
     * 模块中文显示
     * @param mixed $value
     * @param array $data
     * @return string
     */
    public function getModuleTextAttr($value, $data)
    {
        $value = $value ? $value : (isset($data['module']) ? $data['module'] : '');
        $list = $this->getModuleList();
        return isset($list[$value]) ? $list[$value] : '';
    }

    /**
     * 类型中文显示
     * @param mixed $value
     * @param array $data
     * @return string
     */
    public function getTypeTextAttr($value, $data)
    {
        $value = $value ? $value : (isset($data['type']) ? $data['type'] : '');
        $list = $this->getTypeList();
        return isset($list[$value]) ? $list[$value] : '';
    }

    /**
     * 关联分类模型
     * @return \think\model\relation\BelongsTo
     */
    public function category()
    {
        return $this->belongsTo('app\admin\model\wanlshop\Category', 'category_id', 'id', [], 'LEFT')
            ->setEagerlyType(0)
            ->field('id,name');
    }
}
