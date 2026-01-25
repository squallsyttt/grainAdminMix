-- 商品品牌/等级类目（多级）配置表
-- 创建日期: 2026-01-25
-- 说明: 与现有商品类目（wanlshop_category）隔离，通过 type 区分 brand/grade

CREATE TABLE IF NOT EXISTS `grain_wanlshop_goods_meta_category` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `pid` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '父级ID',
  `type` varchar(16) NOT NULL DEFAULT 'brand' COMMENT '类型: brand/grade',
  `name` varchar(64) NOT NULL DEFAULT '' COMMENT '名称',
  `weigh` int(10) NOT NULL DEFAULT '0' COMMENT '排序',
  `status` enum('normal','hidden') NOT NULL DEFAULT 'normal' COMMENT '状态: normal=显示, hidden=隐藏',
  `createtime` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '创建时间',
  `updatetime` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_pid` (`pid`),
  KEY `idx_type_pid` (`type`,`pid`),
  KEY `idx_type_status` (`type`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品品牌/等级类目（多级）';

