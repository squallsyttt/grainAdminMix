define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'wanlshop/voucher.verification/index' + location.search,
                    add_url: '',
                    edit_url: '',
                    del_url: '',
                    multi_url: '',
                    table: 'wanlshop_voucher_verification',
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
                        {field: 'voucher.voucher_no', title: '券号'},
                        {field: 'voucher.goods_title', title: '商品'},
                        {field: 'user.username', title: '用户', align: 'left', formatter: Table.api.formatter.search},
                        {field: 'shop_name', title: '店铺', align: 'left'},
                        {field: 'supply_price', title: '供货价', operate: 'BETWEEN'},
                        {field: 'face_value', title: '面值', operate: 'BETWEEN'},
                        {
                            field: 'verify_method',
                            title: '核销方式',
                            searchList: {'code': '验证码', 'scan': '扫码'},
                            formatter: Table.api.formatter.normal
                        },
                        {
                            field: 'createtime',
                            title: '核销时间',
                            operate: 'RANGE',
                            addclass: 'datetimerange',
                            formatter: Table.api.formatter.datetime
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

