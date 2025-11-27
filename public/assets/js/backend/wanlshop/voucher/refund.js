define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'wanlshop/voucher.refund/index' + location.search,
                    add_url: '',
                    edit_url: '',
                    del_url: '',
                    multi_url: '',
                    table: 'wanlshop_voucher_refund',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: 'ID'},
                        {field: 'refund_no', title: '退款单号'},
                        {field: 'voucher.voucher_no', title: '券号'},
                        {field: 'voucher.goods_title', title: '商品', operate: false},
                        {field: 'user.nickname', title: '用户', align: 'left', formatter: Table.api.formatter.search},
                        {field: 'refund_amount', title: '退款金额', operate: 'BETWEEN'},
                        {field: 'refund_reason', title: '退款理由', operate: false},
                        {
                            field: 'state',
                            title: '退款状态',
                            searchList: {'0': '申请中', '1': '同意退款', '2': '拒绝退款', '3': '退款成功'},
                            formatter: Controller.api.formatter.state
                        },
                        {field: 'refuse_reason', title: '拒绝理由', operate: false},
                        {
                            field: 'createtime',
                            title: '申请时间',
                            operate: 'RANGE',
                            addclass: 'datetimerange',
                            formatter: Table.api.formatter.datetime
                        },
                        {
                            field: 'operate',
                            title: __('Operate'),
                            table: table,
                            events: Controller.api.events.operate,
                            formatter: Controller.api.formatter.operate
                        }
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);

            // 绑定同意退款按钮事件
            $(document).on('click', '.btn-approve', function () {
                var id = $(this).data('id');
                Layer.confirm('确定同意此退款申请吗？将调用微信退款接口进行退款。', {
                    title: '同意退款',
                    btn: ['确定', '取消']
                }, function (index) {
                    $.ajax({
                        url: 'wanlshop/voucher.refund/approve',
                        type: 'POST',
                        data: {id: id},
                        dataType: 'json',
                        success: function (ret) {
                            Layer.close(index);
                            if (ret.code === 1) {
                                Toastr.success(ret.msg);
                                table.bootstrapTable('refresh');
                            } else {
                                Toastr.error(ret.msg);
                            }
                        },
                        error: function () {
                            Layer.close(index);
                            Toastr.error('请求失败');
                        }
                    });
                });
            });

            // 绑定拒绝退款按钮事件
            $(document).on('click', '.btn-reject', function () {
                var id = $(this).data('id');
                Layer.prompt({
                    title: '请输入拒绝理由',
                    formType: 2,
                    maxlength: 200,
                    btn: ['确定', '取消']
                }, function (value, index) {
                    if (!value || value.trim() === '') {
                        Toastr.error('请填写拒绝理由');
                        return;
                    }
                    $.ajax({
                        url: 'wanlshop/voucher.refund/reject',
                        type: 'POST',
                        data: {id: id, refuse_reason: value},
                        dataType: 'json',
                        success: function (ret) {
                            Layer.close(index);
                            if (ret.code === 1) {
                                Toastr.success(ret.msg);
                                table.bootstrapTable('refresh');
                            } else {
                                Toastr.error(ret.msg);
                            }
                        },
                        error: function () {
                            Layer.close(index);
                            Toastr.error('请求失败');
                        }
                    });
                });
            });

            // 绑定确认完成按钮事件
            $(document).on('click', '.btn-complete', function () {
                var id = $(this).data('id');
                Layer.confirm('确定手动标记此退款为已完成吗？', {
                    title: '确认退款完成',
                    btn: ['确定', '取消']
                }, function (index) {
                    $.ajax({
                        url: 'wanlshop/voucher.refund/complete',
                        type: 'POST',
                        data: {id: id},
                        dataType: 'json',
                        success: function (ret) {
                            Layer.close(index);
                            if (ret.code === 1) {
                                Toastr.success(ret.msg);
                                table.bootstrapTable('refresh');
                            } else {
                                Toastr.error(ret.msg);
                            }
                        },
                        error: function () {
                            Layer.close(index);
                            Toastr.error('请求失败');
                        }
                    });
                });
            });
        },
        detail: function () {
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            },
            formatter: {
                // 状态格式化器
                state: function (value, row, index) {
                    var stateMap = {
                        '0': {text: '申请中', class: 'label-warning'},
                        '1': {text: '同意退款', class: 'label-info'},
                        '2': {text: '拒绝退款', class: 'label-danger'},
                        '3': {text: '退款成功', class: 'label-success'}
                    };
                    var state = stateMap[value] || {text: '未知', class: 'label-default'};
                    return '<span class="label ' + state.class + '">' + state.text + '</span>';
                },
                // 操作按钮格式化器
                operate: function (value, row, index) {
                    var buttons = [];

                    // 申请中状态：显示同意和拒绝按钮
                    if (row.state === '0' || row.state === 0) {
                        buttons.push('<a href="javascript:;" class="btn btn-xs btn-success btn-approve" data-id="' + row.id + '" title="同意退款"><i class="fa fa-check"></i> 同意</a>');
                        buttons.push('<a href="javascript:;" class="btn btn-xs btn-danger btn-reject" data-id="' + row.id + '" title="拒绝退款"><i class="fa fa-times"></i> 拒绝</a>');
                    }

                    // 同意退款状态：显示确认完成按钮（用于手动确认，正常情况由微信回调处理）
                    if (row.state === '1' || row.state === 1) {
                        buttons.push('<a href="javascript:;" class="btn btn-xs btn-primary btn-complete" data-id="' + row.id + '" title="确认完成"><i class="fa fa-check-circle"></i> 确认完成</a>');
                    }

                    // 如果没有可用按钮，显示状态提示
                    if (buttons.length === 0) {
                        if (row.state === '2' || row.state === 2) {
                            return '<span class="text-muted">已拒绝</span>';
                        }
                        if (row.state === '3' || row.state === 3) {
                            return '<span class="text-success">已完成</span>';
                        }
                    }

                    return buttons.join(' ');
                }
            },
            events: {
                operate: {}
            }
        }
    };
    return Controller;
});
