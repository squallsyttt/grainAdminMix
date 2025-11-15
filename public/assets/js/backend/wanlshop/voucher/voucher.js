define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'wanlshop/voucher.voucher/index' + location.search,
                    add_url: '',
                    edit_url: '',
                    del_url: 'wanlshop/voucher.voucher/del',
                    multi_url: 'wanlshop/voucher.voucher/multi',
                    table: 'wanlshop_voucher',
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
                        {field: 'voucher_no', title: '券号'},
                        {field: 'verify_code', title: '验证码'},
                        {field: 'user.username', title: '用户', align: 'left', formatter: Table.api.formatter.search},
                        {field: 'goods_title', title: '商品', align: 'left'},
                        {field: 'shop_name', title: '核销店铺', align: 'left'},
                        {field: 'face_value', title: '面值', operate: 'BETWEEN'},
                        {field: 'supply_price', title: '供货价', operate: 'BETWEEN'},
                        {
                            field: 'state',
                            title: '券状态',
                            searchList: {'1': '未使用', '2': '已核销', '3': '已过期', '4': '已退款'},
                            formatter: Table.api.formatter.normal
                        },
                        {
                            field: 'valid_start',
                            title: '有效期开始',
                            operate: 'RANGE',
                            addclass: 'datetimerange',
                            formatter: Table.api.formatter.datetime
                        },
                        {
                            field: 'valid_end',
                            title: '有效期结束',
                            operate: 'RANGE',
                            addclass: 'datetimerange',
                            formatter: Table.api.formatter.datetime
                        },
                        {
                            field: 'createtime',
                            title: '创建时间',
                            operate: 'RANGE',
                            addclass: 'datetimerange',
                            formatter: Table.api.formatter.datetime
                        },
                        {
                            field: 'verifytime',
                            title: '核销时间',
                            operate: 'RANGE',
                            addclass: 'datetimerange',
                            formatter: Table.api.formatter.datetime
                        },
                        {
                            field: 'refundtime',
                            title: '退款时间',
                            operate: 'RANGE',
                            addclass: 'datetimerange',
                            formatter: Table.api.formatter.datetime
                        },
                        {
                            field: 'status',
                            title: '行状态',
                            searchList: {'normal': 'Normal', 'hidden': 'Hidden'},
                            formatter: Table.api.formatter.status
                        },
                        {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate, formatter: Table.api.formatter.operate}
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);
        },
        add: function () {
            Controller.api.bindevent();
        },
        edit: function () {
            Controller.api.bindevent();
        },
        detail: function () {
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };
    return Controller;
});

