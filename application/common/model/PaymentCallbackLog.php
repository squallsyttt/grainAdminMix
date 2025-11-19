<?php

namespace app\common\model;

use think\Model;

/**
 * 微信支付回调日志模型
 */
class PaymentCallbackLog extends Model
{
    // 表名（不含 grain_ 前缀）
    protected $name = 'wanlshop_payment_callback_log';

    // 自动写入时间戳字段
    protected $autoWriteTimestamp = 'int';

    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    /**
     * 记录支付回调日志
     *
     * @param array $data
     * @return static
     */
    public static function recordCallback(array $data): self
    {
        $payload = [
            'order_type'       => $data['order_type'] ?? '',
            'order_no'         => $data['order_no'] ?? '',
            'transaction_id'   => $data['transaction_id'] ?? null,
            'trade_state'      => $data['trade_state'] ?? null,
            'callback_body'    => self::normalizeJsonField($data['callback_body'] ?? null),
            'callback_headers' => self::normalizeJsonField($data['callback_headers'] ?? null),
            'verify_result'    => $data['verify_result'] ?? null,
            'process_status'   => 'pending',
        ];

        return self::create($payload);
    }

    /**
     * 更新处理状态
     *
     * @param string $status success/fail
     * @param array  $result 处理结果详情
     * @return bool
     */
    public function markProcessed(string $status, array $result): bool
    {
        if (!in_array($status, ['success', 'fail'])) {
            throw new \InvalidArgumentException('status 仅支持 success/fail');
        }

        $this->process_status = $status;
        $this->process_result = json_encode($result, JSON_UNESCAPED_UNICODE);
        $this->updatetime = time();

        return $this->save() !== false;
    }

    /**
     * 判断指定微信支付单是否已处理成功
     *
     * @param string $transactionId
     * @return bool
     */
    public static function isProcessed(string $transactionId): bool
    {
        if ($transactionId === '') {
            return false;
        }

        return self::where('transaction_id', $transactionId)
            ->where('process_status', 'success')
            ->value('id') ? true : false;
    }

    /**
     * 将数组/对象字段序列化为 JSON 字符串
     *
     * @param mixed $value
     * @return string|null
     */
    protected static function normalizeJsonField($value): ?string
    {
        if (is_null($value)) {
            return null;
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        return (string) $value;
    }
}

