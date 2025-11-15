-- 核销券系统权限规则和菜单配置
-- 创建日期: 2025-11-14
-- 说明: 为管理后台添加核销券模块的权限规则和菜单

-- 1. 添加顶级菜单 - 核销券管理
INSERT INTO `grain_auth_rule` (`id`, `type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`) VALUES
(NULL, 'file', 0, 'wanlshop/voucher', '核销券管理', 'fa fa-ticket', '', '核销券订单与券管理', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 90, 'normal');

-- 获取刚插入的顶级菜单ID（假设为 @voucher_menu_id）
SET @voucher_menu_id = LAST_INSERT_ID();

-- 2. 添加子菜单 - 订单管理
INSERT INTO `grain_auth_rule` (`id`, `type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`) VALUES
(NULL, 'file', @voucher_menu_id, 'wanlshop/voucher.order', '订单管理', 'fa fa-list-alt', '', '核销券订单列表', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 100, 'normal');

SET @order_menu_id = LAST_INSERT_ID();

-- 订单管理权限规则
INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`) VALUES
('file', @order_menu_id, 'wanlshop/voucher.order/index', '查看', '', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @order_menu_id, 'wanlshop/voucher.order/detail', '详情', '', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @order_menu_id, 'wanlshop/voucher.order/del', '删除', '', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @order_menu_id, 'wanlshop/voucher.order/multi', '批量更新', '', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal');

-- 3. 添加子菜单 - 核销券管理
INSERT INTO `grain_auth_rule` (`id`, `type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`) VALUES
(NULL, 'file', @voucher_menu_id, 'wanlshop/voucher.voucher', '核销券管理', 'fa fa-ticket', '', '核销券列表', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 99, 'normal');

SET @voucher_submenu_id = LAST_INSERT_ID();

-- 核销券管理权限规则
INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`) VALUES
('file', @voucher_submenu_id, 'wanlshop/voucher.voucher/index', '查看', '', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @voucher_submenu_id, 'wanlshop/voucher.voucher/detail', '详情', '', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @voucher_submenu_id, 'wanlshop/voucher.voucher/cancel', '作废', '', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @voucher_submenu_id, 'wanlshop/voucher.voucher/del', '删除', '', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @voucher_submenu_id, 'wanlshop/voucher.voucher/multi', '批量更新', '', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal');

-- 4. 添加子菜单 - 核销记录
INSERT INTO `grain_auth_rule` (`id`, `type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`) VALUES
(NULL, 'file', @voucher_menu_id, 'wanlshop/voucher.verification', '核销记录', 'fa fa-check-square', '', '核销记录列表', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 98, 'normal');

SET @verification_menu_id = LAST_INSERT_ID();

-- 核销记录权限规则
INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`) VALUES
('file', @verification_menu_id, 'wanlshop/voucher.verification/index', '查看', '', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @verification_menu_id, 'wanlshop/voucher.verification/detail', '详情', '', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal');

-- 5. 添加子菜单 - 结算管理
INSERT INTO `grain_auth_rule` (`id`, `type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`) VALUES
(NULL, 'file', @voucher_menu_id, 'wanlshop/voucher.settlement', '结算管理', 'fa fa-dollar', '', '结算记录管理', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 97, 'normal');

SET @settlement_menu_id = LAST_INSERT_ID();

-- 结算管理权限规则
INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`) VALUES
('file', @settlement_menu_id, 'wanlshop/voucher.settlement/index', '查看', '', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @settlement_menu_id, 'wanlshop/voucher.settlement/detail', '详情', '', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @settlement_menu_id, 'wanlshop/voucher.settlement/settle', '标记已结算', '', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal');

-- 6. 添加子菜单 - 退款管理
INSERT INTO `grain_auth_rule` (`id`, `type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`) VALUES
(NULL, 'file', @voucher_menu_id, 'wanlshop/voucher.refund', '退款管理', 'fa fa-reply', '', '退款审核管理', 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 96, 'normal');

SET @refund_menu_id = LAST_INSERT_ID();

-- 退款管理权限规则
INSERT INTO `grain_auth_rule` (`type`, `pid`, `name`, `title`, `icon`, `condition`, `remark`, `ismenu`, `createtime`, `updatetime`, `weigh`, `status`) VALUES
('file', @refund_menu_id, 'wanlshop/voucher.refund/index', '查看', '', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @refund_menu_id, 'wanlshop/voucher.refund/detail', '详情', '', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @refund_menu_id, 'wanlshop/voucher.refund/approve', '同意退款', '', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @refund_menu_id, 'wanlshop/voucher.refund/reject', '拒绝退款', '', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal'),
('file', @refund_menu_id, 'wanlshop/voucher.refund/complete', '确认完成', '', '', '', 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 0, 'normal');
