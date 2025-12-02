-- 核销券返利管理菜单与权限
-- 创建日期: 2025-12-02
-- 说明: 在核销券管理下新增返利管理菜单及权限规则

-- 获取“核销券管理”顶级菜单ID
SET @voucher_menu_id = (SELECT `id` FROM `grain_auth_rule` WHERE `name` = 'wanlshop/voucher' LIMIT 1);

-- 添加子菜单 - 返利管理
INSERT INTO `grain_auth_rule` (`id`, `type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`) VALUES
(NULL, 'file', @voucher_menu_id, 'wanlshop/voucher.rebate', '返利管理', 'fa fa-percent', '', '核销券返利记录管理', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 95, 'normal');

SET @rebate_menu_id = LAST_INSERT_ID();

-- 返利管理权限规则
INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`) VALUES
('file', @rebate_menu_id, 'wanlshop/voucher.rebate/index', '查看', '', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @rebate_menu_id, 'wanlshop/voucher.rebate/detail', '详情', '', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal');
