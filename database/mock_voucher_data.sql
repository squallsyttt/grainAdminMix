-- Mock data for Voucher Order & Payment module
-- Database: grainPro

SET NAMES utf8mb4;
USE `grainPro`;

-- 1. Mock user（核销券体验用户）
INSERT INTO `grain_user` (id, group_id, username, nickname, mobile, money, score, jointime, logintime, status)
SELECT 1001, 1, 'voucher_user', '核销券体验用户', '13100001111', 0.00, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 'normal'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `grain_user` WHERE id = 1001);

-- 2. Mock shop（核销券体验店）
INSERT INTO `grain_wanlshop_shop` (id, user_id, shopname, keywords, description, avatar, state, level, islive, isself, bio, city, `return`, weigh, verify, createtime, updatetime, status)
SELECT 1001, 1001, '核销券体验店', '核销券,体验店', '用于核销券模块演示的测试商家', '', '1', 1, 0, 1,
       '这是一家用于核销券功能演示的测试门店', '广东省 深圳市', '广东省深圳市测试区测试路1号', 0, '3',
       UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 'normal'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `grain_wanlshop_shop` WHERE id = 1001);

-- 3. Mock goods（核销券商品）
-- 使用已存在的 goods 类目 10（女装）作为示例分类
INSERT INTO `grain_wanlshop_goods` (
  id, shop_id, category_id, title, image, images, description,
  stock, content, freight_id, grounding, specs, distribution,
  activity, activity_id, activity_type, views, price, sales, payment,
  comment, praise, moderate, negative, `like`, weigh, createtime, updatetime, status
)
SELECT
  1001,
  1001,
  10,
  '测试核销券商品',
  '/assets/img/mock/voucher_goods.jpg',
  '',
  '用于核销券订单演示的商品',
  'payment',
  '这里是商品详情，用于核销券演示',
  0,
  1,
  'single',
  'false',
  '0',
  0,
  'goods',
  0,
  80.00,
  0,
  0,
  0,
  0,
  0,
  0,
  0,
  0,
  UNIX_TIMESTAMP(),
  UNIX_TIMESTAMP(),
  'normal'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `grain_wanlshop_goods` WHERE id = 1001);

-- 4. Mock 小程序 openid 绑定（用于 JSAPI 预下单）
INSERT INTO `grain_wanlshop_third` (
  id, user_id, token, platform, openid, openname,
  access_token, refresh_token, unionid,
  expires_in, createtime, updatetime, logintime, expiretime
)
SELECT
  1001,
  1001,
  '',
  'miniprogram',
  'oTEST_openid_voucher_user',
  '核销券体验用户',
  '',
  '',
  '',
  7776000,
  UNIX_TIMESTAMP(),
  UNIX_TIMESTAMP(),
  UNIX_TIMESTAMP(),
  UNIX_TIMESTAMP() + 7776000
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `grain_wanlshop_third` WHERE id = 1001);

-- 5. Mock 核销券订单（已支付订单）
INSERT INTO `grain_wanlshop_voucher_order` (
  id, user_id, order_no, category_id, goods_id, coupon_id,
  quantity, supply_price, retail_price, coupon_price, discount_price,
  actual_payment, state, remarks,
  createtime, paymenttime, canceltime, updatetime, deletetime, status
)
SELECT
  1001,
  1001,
  'ORD202511150001',
  10,
  1001,
  0,
  2,
  50.00,
  80.00,
  0.00,
  0.00,
  80.00,
  '2',
  '模拟已支付的核销券订单',
  UNIX_TIMESTAMP() - 7200,
  UNIX_TIMESTAMP() - 3600,
  NULL,
  UNIX_TIMESTAMP() - 1800,
  NULL,
  'normal'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `grain_wanlshop_voucher_order` WHERE id = 1001);

-- 6. Mock 核销券（1 张已核销，1 张未使用）
-- 券 1：已核销
INSERT INTO `grain_wanlshop_voucher` (
  id, voucher_no, order_id, user_id, category_id, goods_id,
  goods_title, goods_image, supply_price, face_value,
  shop_id, shop_name, verify_user_id, verify_code,
  state, valid_start, valid_end,
  createtime, verifytime, refundtime,
  updatetime, deletetime, status
)
SELECT
  1001,
  'VCH202511150001',
  1001,
  1001,
  10,
  1001,
  '测试核销券商品',
  '/assets/img/mock/voucher_goods.jpg',
  25.00,
  40.00,
  1001,
  '核销券体验店',
  1001,
  '123456',
  '2',
  UNIX_TIMESTAMP() - 3600,
  UNIX_TIMESTAMP() + 30 * 86400,
  UNIX_TIMESTAMP() - 3600,
  UNIX_TIMESTAMP() - 1800,
  NULL,
  UNIX_TIMESTAMP() - 900,
  NULL,
  'normal'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `grain_wanlshop_voucher` WHERE id = 1001);

-- 券 2：未使用
INSERT INTO `grain_wanlshop_voucher` (
  id, voucher_no, order_id, user_id, category_id, goods_id,
  goods_title, goods_image, supply_price, face_value,
  shop_id, shop_name, verify_user_id, verify_code,
  state, valid_start, valid_end,
  createtime, verifytime, refundtime,
  updatetime, deletetime, status
)
SELECT
  1002,
  'VCH202511150002',
  1001,
  1001,
  10,
  1001,
  '测试核销券商品',
  '/assets/img/mock/voucher_goods.jpg',
  25.00,
  40.00,
  0,
  '',
  0,
  '654321',
  '1',
  UNIX_TIMESTAMP() - 3600,
  UNIX_TIMESTAMP() + 30 * 86400,
  UNIX_TIMESTAMP() - 3600,
  NULL,
  NULL,
  UNIX_TIMESTAMP() - 900,
  NULL,
  'normal'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `grain_wanlshop_voucher` WHERE id = 1002);

-- 7. Mock 核销记录（对应券 1）
INSERT INTO `grain_wanlshop_voucher_verification` (
  id, voucher_id, voucher_no, user_id,
  shop_id, shop_name, verify_user_id,
  supply_price, face_value, verify_method,
  remarks, createtime, updatetime, deletetime, status
)
SELECT
  1001,
  1001,
  'VCH202511150001',
  1001,
  1001,
  '核销券体验店',
  1001,
  25.00,
  40.00,
  'code',
  '模拟验证码核销记录',
  UNIX_TIMESTAMP() - 1800,
  UNIX_TIMESTAMP() - 900,
  NULL,
  'normal'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `grain_wanlshop_voucher_verification` WHERE id = 1001);

-- 8. Mock 结算记录（对应券 1，待结算）
INSERT INTO `grain_wanlshop_voucher_settlement` (
  id, settlement_no, voucher_id, voucher_no, order_id,
  shop_id, shop_name, user_id,
  retail_price, supply_price, platform_amount, shop_amount,
  state, settlement_time, remarks,
  createtime, updatetime, deletetime, status
)
SELECT
  1001,
  'STL202511150001',
  1001,
  'VCH202511150001',
  1001,
  1001,
  '核销券体验店',
  1001,
  40.00,
  25.00,
  15.00,
  25.00,
  '1',
  NULL,
  '模拟核销后待结算记录',
  UNIX_TIMESTAMP() - 900,
  UNIX_TIMESTAMP() - 600,
  NULL,
  'normal'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `grain_wanlshop_voucher_settlement` WHERE id = 1001);

-- 9. Mock 已退款订单 + 券 + 退款记录

-- 9.1 已退款订单（已支付）
INSERT INTO `grain_wanlshop_voucher_order` (
  id, user_id, order_no, category_id, goods_id, coupon_id,
  quantity, supply_price, retail_price, coupon_price, discount_price,
  actual_payment, state, remarks,
  createtime, paymenttime, canceltime, updatetime, deletetime, status
)
SELECT
  1002,
  1001,
  'ORD202511150002',
  10,
  1001,
  0,
  1,
  25.00,
  40.00,
  0.00,
  0.00,
  40.00,
  '2',
  '模拟已退款的核销券订单',
  UNIX_TIMESTAMP() - 7200,
  UNIX_TIMESTAMP() - 3600,
  NULL,
  UNIX_TIMESTAMP() - 600,
  NULL,
  'normal'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `grain_wanlshop_voucher_order` WHERE id = 1002);

-- 9.2 已退款的核销券（未使用直接退款）
INSERT INTO `grain_wanlshop_voucher` (
  id, voucher_no, order_id, user_id, category_id, goods_id,
  goods_title, goods_image, supply_price, face_value,
  shop_id, shop_name, verify_user_id, verify_code,
  state, valid_start, valid_end,
  createtime, verifytime, refundtime,
  updatetime, deletetime, status
)
SELECT
  1003,
  'VCH202511150003',
  1002,
  1001,
  10,
  1001,
  '测试核销券商品',
  '/assets/img/mock/voucher_goods.jpg',
  25.00,
  40.00,
  0,
  '',
  0,
  '223344',
  '4',
  UNIX_TIMESTAMP() - 3600,
  UNIX_TIMESTAMP() + 30 * 86400,
  UNIX_TIMESTAMP() - 3600,
  NULL,
  UNIX_TIMESTAMP() - 300,
  UNIX_TIMESTAMP() - 300,
  NULL,
  'normal'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `grain_wanlshop_voucher` WHERE id = 1003);

-- 9.3 退款记录（对应券 3）
INSERT INTO `grain_wanlshop_voucher_refund` (
  id, refund_no, voucher_id, voucher_no, order_id, user_id,
  refund_amount, refund_reason, refuse_reason, state,
  createtime, updatetime, deletetime, status
)
SELECT
  1001,
  'RFD202511150001',
  1003,
  'VCH202511150003',
  1002,
  1001,
  40.00,
  '不想要了（模拟退款完成）',
  '',
  '3',
  UNIX_TIMESTAMP() - 900,
  UNIX_TIMESTAMP() - 300,
  NULL,
  'normal'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `grain_wanlshop_voucher_refund` WHERE id = 1001);

