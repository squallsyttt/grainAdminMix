define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'wanlshop/voucher.settlement/index' + location.search,
                    add_url: '',
                    edit_url: '',
                    del_url: '',
                    multi_url: '',
                    table: 'wanlshop_voucher_settlement',
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
                        {field: 'settlement_no', title: '结算单号'},
                        {field: 'voucher.voucher_no', title: '券号'},
                        {field: 'shop_name', title: '店铺', align: 'left'},
                        {field: 'user.username', title: '用户', align: 'left', formatter: Table.api.formatter.search},
                        {field: 'retail_price', title: '零售价', operate: 'BETWEEN'},
                        {field: 'supply_price', title: '供货价', operate: 'BETWEEN'},
                        {field: 'shop_amount', title: '商家结算金额', operate: 'BETWEEN'},
                        {field: 'platform_amount', title: '平台利润', operate: 'BETWEEN'},
                        {
                            field: 'state',
                            title: '结算状态',
                            searchList: {'1': '待结算', '2': '已结算', '3': '打款中', '4': '打款失败'},
                            formatter: Controller.api.formatter.state
                        },
                        {
                            field: 'createtime',
                            title: '创建时间',
                            operate: 'RANGE',
                            addclass: 'datetimerange',
                            formatter: Table.api.formatter.datetime
                        },
                        {
                            field: 'settlement_time',
                            title: '结算时间',
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
                        },
                        {
                            field: 'status',
                            title: '行状态',
                            searchList: {'normal': 'Normal', 'hidden': 'Hidden'},
                            formatter: Table.api.formatter.status
                        }
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);

            // 点击结算打款
            $(document).on('click', '.btn-transfer', function () {
                var settlementId = $(this).data('id');
                Fast.api.open('wanlshop/voucher.settlement/transfer?ids=' + settlementId, '结算打款', {
                    callback: function () {
                        table.bootstrapTable('refresh');
                    }
                });
            });

            // 重试打款
            $(document).on('click', '.btn-retry', function () {
                var settlementId = $(this).data('id');
                layer.confirm('确认重试打款？', {
                    title: '重试打款',
                    btn: ['确定', '取消']
                }, function (index) {
                    Fast.api.ajax({
                        url: 'wanlshop/voucher.settlement/retry',
                        data: {settlement_id: settlementId}
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
                // 状态展示
                state: function (value, row, index) {
                    var stateMap = {
                        '1': {text: '待结算', className: 'label-warning'},
                        '2': {text: '已结算', className: 'label-success'},
                        '3': {text: '打款中', className: 'label-info'},
                        '4': {text: '打款失败', className: 'label-danger'}
                    };
                    var current = stateMap[value] || {text: '未知', className: 'label-default'};
                    return '<span class="label ' + current.className + '">' + current.text + '</span>';
                },
                // 操作栏按钮
                operate: function (value, row, index) {
                    var buttons = [];
                    var state = row.state;
                    var amount = row.shop_amount || row.supply_price || '';

                    if (state === '1' || state === 1 || state === '4' || state === 4) {
                        buttons.push('<a href="javascript:;" class="btn btn-xs btn-success btn-transfer" data-id="' + row.id + '" data-amount="' + amount + '"><i class="fa fa-paypal"></i> 结算打款</a>');
                    }

                    if (state === '4' || state === 4) {
                        buttons.push('<a href="javascript:;" class="btn btn-xs btn-warning btn-retry" data-id="' + row.id + '"><i class="fa fa-refresh"></i> 重试</a>');
                    }

                    if (buttons.length === 0) {
                        return '<span class="text-muted">--</span>';
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
