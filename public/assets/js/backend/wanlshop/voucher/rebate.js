define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var stageMap = {
        free: '免费期',
        welfare: '福利损耗期',
        goods: '货物损耗期',
        expired: '已过期'
    };

    var paymentStatusMap = {
        unpaid: {text: '未打款', className: 'label-warning'},
        pending: {text: '打款中', className: 'label-info'},
        paid: {text: '已打款', className: 'label-success'},
        failed: {text: '打款失败', className: 'label-danger'}
    };

    var rebateTypeMap = {
        normal: {text: '核销返利', className: 'label-default'},
        custody: {text: '代管理返利', className: 'label-primary'},
        shop_invite: {text: '店铺邀请返利', className: 'label-info'}
    };

    var custodyRefundStatusMap = {
        none: {text: '无退款', className: 'label-default'},
        pending: {text: '退款中', className: 'label-info'},
        success: {text: '退款成功', className: 'label-success'},
        failed: {text: '退款失败', className: 'label-danger'}
    };

    var Formatter = {
        money: function (value) {
            if (value === null || value === undefined || value === '') {
                return '-';
            }
            var number = parseFloat(value);
            if (isNaN(number)) {
                return '-';
            }
            return '¥' + number.toFixed(2);
        },
        percent: function (value) {
            if (value === null || value === undefined || value === '') {
                return '-';
            }
            var number = parseFloat(value);
            if (isNaN(number)) {
                return '-';
            }
            return number.toFixed(2) + '%';
        },
        paymentStatus: function (value, row) {
            var current = paymentStatusMap[value] || {text: '未知', className: 'label-default'};
            var html = '<span class="label ' + current.className + '">' + current.text + '</span>';
            // 显示剩余天数提示（店铺邀请返利不显示，因为无需等待7天）
            if (value === 'unpaid' && row.days_until_transfer > 0 && row.rebate_type !== 'shop_invite') {
                html += ' <small class="text-muted">(' + row.days_until_transfer + '天后可打)</small>';
            }
            return html;
        },
        rebateType: function (value, row) {
            var current = rebateTypeMap[value] || {text: '核销返利', className: 'label-default'};
            return '<span class="label ' + current.className + '">' + current.text + '</span>';
        },
        custodyRefundStatus: function (value, row) {
            // 只有代管理返利才显示退款状态
            if (row.rebate_type !== 'custody') {
                return '-';
            }
            var current = custodyRefundStatusMap[value] || {text: '无退款', className: 'label-default'};
            return '<span class="label ' + current.className + '">' + current.text + '</span>';
        },
        // 代管理返利总金额（返利 + 退款）
        totalAmount: function (value, row) {
            if (row.rebate_type !== 'custody') {
                return Formatter.money(row.rebate_amount);
            }
            var rebate = parseFloat(row.rebate_amount) || 0;
            var refund = parseFloat(row.refund_amount) || 0;
            var total = rebate + refund;
            if (refund > 0) {
                return '¥' + total.toFixed(2) + ' <small class="text-muted">(返利' + rebate.toFixed(2) + '+退款' + refund.toFixed(2) + ')</small>';
            }
            return '¥' + total.toFixed(2);
        }
    };

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'wanlshop/voucher.rebate/index' + location.search,
                    detail_url: 'wanlshop/voucher.rebate/detail',
                    add_url: '',
                    edit_url: '',
                    del_url: '',
                    multi_url: '',
                    table: 'wanlshop_voucher_rebate',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                search: false,
                commonSearch: true,
                searchFormVisible: true,
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: 'ID'},
                        {field: 'voucher.voucher_no', title: '券号', operate: 'LIKE'},
                        {
                            field: 'rebate_type',
                            title: '返利类型',
                            operate: '=',
                            searchList: {'normal': '核销返利', 'custody': '代管理返利', 'shop_invite': '店铺邀请返利'},
                            formatter: Formatter.rebateType
                        },
                        {field: 'goods_title', title: '商品标题', align: 'left', operate: 'LIKE'},
                        {field: 'user.nickname', title: '用户昵称', operate: 'LIKE', formatter: Table.api.formatter.search},
                        {field: 'user_id', title: '用户ID', operate: '='},
                        {field: 'shop.shopname', title: '店铺名称', operate: 'LIKE', formatter: Table.api.formatter.search},
                        {field: 'shop_id', title: '店铺ID', operate: '='},
                        {
                            field: 'stage',
                            title: '返利阶段',
                            operate: '=',
                            searchList: stageMap,
                            formatter: Table.api.formatter.normal
                        },
                        {field: 'actual_bonus_ratio', title: '实际返利比例', operate: 'BETWEEN', formatter: Formatter.percent},
                        {field: 'face_value', title: '返利基数', operate: 'BETWEEN', formatter: Formatter.money},
                        {
                            field: 'rebate_amount',
                            title: '返利金额',
                            operate: 'BETWEEN',
                            formatter: Formatter.money
                        },
                        {
                            field: 'refund_amount',
                            title: '等量退款',
                            operate: 'BETWEEN',
                            formatter: function (value, row) {
                                if (row.rebate_type !== 'custody') {
                                    return '-';
                                }
                                return Formatter.money(value);
                            }
                        },
                        {
                            field: 'custody_refund_status',
                            title: '退款状态',
                            searchList: {'none': '无退款', 'pending': '退款中', 'success': '退款成功', 'failed': '退款失败'},
                            formatter: Formatter.custodyRefundStatus
                        },
                        {
                            field: 'payment_time',
                            title: '付款时间',
                            operate: 'RANGE',
                            addclass: 'datetimerange',
                            formatter: Table.api.formatter.datetime
                        },
                        {
                            field: 'verify_time',
                            title: '核销时间',
                            operate: 'RANGE',
                            addclass: 'datetimerange',
                            formatter: Table.api.formatter.datetime
                        },
                        {
                            field: 'status',
                            title: '行状态',
                            searchList: {'normal': '正常', 'hidden': '隐藏'},
                            formatter: Table.api.formatter.status
                        },
                        {
                            field: 'payment_status',
                            title: '打款状态',
                            searchList: {'unpaid': '未打款', 'pending': '打款中', 'paid': '已打款', 'failed': '打款失败'},
                            formatter: Formatter.paymentStatus
                        },
                        {
                            field: 'operate',
                            title: '操作',
                            table: table,
                            events: Controller.api.events.operate,
                            formatter: Controller.api.formatter.operate
                        }
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);

            // 点击返利打款
            $(document).on('click', '.btn-transfer', function () {
                var rebateId = $(this).data('id');
                Fast.api.open('wanlshop/voucher.rebate/transfer?ids=' + rebateId, '返利打款', {
                    callback: function () {
                        table.bootstrapTable('refresh');
                    }
                });
            });

            // 重试打款
            $(document).on('click', '.btn-retry', function () {
                var rebateId = $(this).data('id');
                layer.confirm('确认重试打款？', {
                    title: '重试打款',
                    btn: ['确定', '取消']
                }, function (index) {
                    Fast.api.ajax({
                        url: 'wanlshop/voucher.rebate/retry',
                        data: {rebate_id: rebateId}
                    }, function () {
                        layer.close(index);
                        Toastr.success('已提交重试');
                        table.bootstrapTable('refresh');
                        return false;
                    }, function () {
                        layer.close(index);
                    });
                });
            });

            // 重试代管理退款
            $(document).on('click', '.btn-retry-refund', function () {
                var rebateId = $(this).data('id');
                layer.confirm('确认重试代管理退款？', {
                    title: '重试退款',
                    btn: ['确定', '取消']
                }, function (index) {
                    Fast.api.ajax({
                        url: 'wanlshop/voucher.rebate/retryRefund',
                        data: {rebate_id: rebateId}
                    }, function () {
                        layer.close(index);
                        Toastr.success('已提交重试');
                        table.bootstrapTable('refresh');
                        return false;
                    }, function () {
                        layer.close(index);
                    });
                });
            });
        },
        detail: function () {
        },
        transfer: function () {
            Controller.api.bindevent();
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            },
            formatter: {
                // 操作栏按钮
                operate: function (value, row, index) {
                    var buttons = [];
                    var paymentStatus = row.payment_status;
                    var canTransfer = row.can_transfer;
                    var rebateType = row.rebate_type;
                    var custodyRefundStatus = row.custody_refund_status;

                    // 详情按钮
                    buttons.push('<a href="javascript:;" class="btn btn-xs btn-info btn-dialog" data-url="wanlshop/voucher.rebate/detail?ids=' + row.id + '" title="查看详情"><i class="fa fa-eye"></i></a>');

                    // 可打款：显示打款按钮
                    if (canTransfer) {
                        buttons.push('<a href="javascript:;" class="btn btn-xs btn-success btn-transfer" data-id="' + row.id + '" title="返利打款"><i class="fa fa-paypal"></i> 打款</a>');
                    }

                    // 打款失败：显示重试按钮
                    if (paymentStatus === 'failed') {
                        buttons.push('<a href="javascript:;" class="btn btn-xs btn-warning btn-retry" data-id="' + row.id + '" title="重试打款"><i class="fa fa-refresh"></i> 重试</a>');
                    }

                    // 代管理退款失败：显示重试退款按钮
                    if (rebateType === 'custody' && custodyRefundStatus === 'failed') {
                        buttons.push('<a href="javascript:;" class="btn btn-xs btn-danger btn-retry-refund" data-id="' + row.id + '" title="重试退款"><i class="fa fa-refresh"></i> 重试退款</a>');
                    }

                    return buttons.join(' ');
                }
            },
            events: {
                operate: {
                    'click .btn-dialog': function (e, value, row, index) {
                        e.stopPropagation();
                        var url = $(this).data('url');
                        Fast.api.open(url, $(this).attr('title') || '详情');
                    }
                }
            }
        }
    };
    return Controller;
});
