/**
 * 订单 Mock 数据
 */

import type { OrderListItem, OrderDetail, OrderItem } from '../../types/order'
import { OrderStatus } from '../../types/order'

/**
 * Mock 订单列表数据
 */
export const mockOrderList: OrderListItem[] = [
  {
    id: 1,
    order_no: 'ORD20250112153045A3B7F2',
    items: [
      {
        id: 101,
        product_name: '东北大米 50kg',
        weight: 50,
        quantity: 1,
        unit_price: 99.00,
        subtotal: 99.00,
        voucher_id: 10001,
        voucher_code: 'GRAIN20250112-001',
        delivery_method: 'pickup',
        store_id: 1,
        store_name: '朝阳门店',
        store_address: '北京市朝阳区测试路1号',
        voucher_status: 'unused',
        voucher_expire_at: Math.floor(Date.now() / 1000) + 86400 * 30
      },
      {
        id: 102,
        product_name: '山东小麦 25kg',
        weight: 25,
        quantity: 2,
        unit_price: 49.50,
        subtotal: 99.00,
        voucher_id: 10002,
        voucher_code: 'GRAIN20250112-002',
        delivery_method: 'pickup',
        store_id: 1,
        store_name: '朝阳门店',
        store_address: '北京市朝阳区测试路1号',
        voucher_status: 'unused',
        voucher_expire_at: Math.floor(Date.now() / 1000) + 86400 * 30
      }
    ],
    total_quantity: 3,
    original_amount: 198.00,
    discount_amount: 50.00,
    final_amount: 148.00,
    status: OrderStatus.PAID,
    createtime: Math.floor(Date.now() / 1000) - 86400 * 1
  },
  {
    id: 2,
    order_no: 'ORD20250111120530B8F3D1',
    items: [
      {
        id: 201,
        product_name: '黑龙江大米 100kg',
        weight: 100,
        quantity: 1,
        unit_price: 199.00,
        subtotal: 199.00,
        voucher_id: 10003,
        voucher_code: 'GRAIN20250111-001',
        delivery_method: 'pickup',
        store_id: 2,
        store_name: '海淀门店',
        store_address: '北京市海淀区中关村大街100号',
        voucher_status: 'used',
        voucher_expire_at: Math.floor(Date.now() / 1000) + 86400 * 30
      },
      {
        id: 202,
        product_name: '山西小米 10kg',
        weight: 10,
        quantity: 1,
        unit_price: 100.00,
        subtotal: 100.00,
        voucher_id: 10004,
        voucher_code: 'GRAIN20250111-002',
        delivery_method: 'pickup',
        store_id: 2,
        store_name: '海淀门店',
        store_address: '北京市海淀区中关村大街100号',
        voucher_status: 'used',
        voucher_expire_at: Math.floor(Date.now() / 1000) + 86400 * 30
      }
    ],
    total_quantity: 2,
    original_amount: 299.00,
    discount_amount: 100.00,
    final_amount: 199.00,
    status: OrderStatus.VERIFIED,
    createtime: Math.floor(Date.now() / 1000) - 86400 * 3
  },
  {
    id: 3,
    order_no: 'ORD20250110093015C2D4E5',
    items: [
      {
        id: 301,
        product_name: '河南小麦 50kg',
        weight: 50,
        quantity: 1,
        unit_price: 79.00,
        subtotal: 79.00,
        voucher_id: 10005,
        voucher_code: 'GRAIN20250110-001',
        delivery_method: 'delivery',
        voucher_status: 'unused',
        voucher_expire_at: Math.floor(Date.now() / 1000) + 86400 * 30
      },
      {
        id: 302,
        product_name: '吉林玉米 30kg',
        weight: 30,
        quantity: 1,
        unit_price: 79.00,
        subtotal: 79.00,
        voucher_id: 10006,
        voucher_code: 'GRAIN20250110-002',
        delivery_method: 'delivery',
        voucher_status: 'unused',
        voucher_expire_at: Math.floor(Date.now() / 1000) + 86400 * 30
      }
    ],
    total_quantity: 2,
    original_amount: 158.00,
    discount_amount: 0,
    final_amount: 158.00,
    status: OrderStatus.PAID,
    createtime: Math.floor(Date.now() / 1000) - 86400 * 5
  },
  {
    id: 4,
    order_no: 'ORD20250109161045D5E6F7',
    items: [
      {
        id: 401,
        product_name: '内蒙古燕麦 20kg',
        weight: 20,
        quantity: 1,
        unit_price: 129.00,
        subtotal: 129.00,
        voucher_id: 10007,
        voucher_code: 'GRAIN20250109-001',
        delivery_method: 'delivery',
        voucher_status: 'used',
        voucher_expire_at: Math.floor(Date.now() / 1000) + 86400 * 30
      },
      {
        id: 402,
        product_name: '陕西小米 15kg',
        weight: 15,
        quantity: 1,
        unit_price: 109.00,
        subtotal: 109.00,
        voucher_id: 10008,
        voucher_code: 'GRAIN20250109-002',
        delivery_method: 'pickup',
        store_id: 3,
        store_name: '东城门店',
        store_address: '北京市东城区王府井大街88号',
        voucher_status: 'used',
        voucher_expire_at: Math.floor(Date.now() / 1000) + 86400 * 30
      },
      {
        id: 403,
        product_name: '云南红米 10kg',
        weight: 10,
        quantity: 1,
        unit_price: 150.00,
        subtotal: 150.00,
        voucher_id: 10009,
        voucher_code: 'GRAIN20250109-003',
        delivery_method: 'delivery',
        voucher_status: 'used',
        voucher_expire_at: Math.floor(Date.now() / 1000) + 86400 * 30
      }
    ],
    total_quantity: 3,
    original_amount: 388.00,
    discount_amount: 50.00,
    final_amount: 338.00,
    status: OrderStatus.VERIFIED,
    createtime: Math.floor(Date.now() / 1000) - 86400 * 7
  },
  {
    id: 5,
    order_no: 'ORD20250108140020E6F7G8',
    items: [
      {
        id: 501,
        product_name: '东北高粱 25kg',
        weight: 25,
        quantity: 1,
        unit_price: 128.00,
        subtotal: 128.00,
        voucher_id: 10010,
        voucher_code: 'GRAIN20250108-001',
        delivery_method: 'pickup',
        store_id: 1,
        store_name: '朝阳门店',
        store_address: '北京市朝阳区测试路1号',
        voucher_status: 'expired',
        voucher_expire_at: Math.floor(Date.now() / 1000) - 86400 * 1
      }
    ],
    total_quantity: 1,
    original_amount: 128.00,
    discount_amount: 30.00,
    final_amount: 98.00,
    status: OrderStatus.CANCELLED,
    createtime: Math.floor(Date.now() / 1000) - 86400 * 10
  }
]

/**
 * Mock 订单详情数据
 */
export const mockOrderDetail: OrderDetail = {
  ...mockOrderList[0],
  remark: '请尽快配送，谢谢！'
}

/**
 * Mock 已核销订单详情
 */
export const mockVerifiedOrderDetail: OrderDetail = {
  ...mockOrderList[1],
  remark: '订单已完成核销'
}

/**
 * Mock 跑腿配送订单详情
 */
export const mockDeliveryOrderDetail: OrderDetail = {
  ...mockOrderList[2],
  remark: '请送到18号楼3单元602室'
}
