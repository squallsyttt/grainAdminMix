define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var stageMap = {
        free: '免费期',
        welfare: '福利损耗期',
        goods: '货物损耗期',
        expired: '已过期'
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
                            field: 'calculated_rebate',
                            title: '返利金额',
                            operate: false,
                            formatter: function (value, row) {
                                var faceValue = parseFloat(row.face_value);
                                var ratio = parseFloat(row.actual_bonus_ratio);
                                if (isNaN(faceValue) || isNaN(ratio)) {
                                    return '-';
                                }
                                var amount = faceValue * ratio / 100;
                                return '¥' + amount.toFixed(2);
                            }
                        },
                        {
                            field: 'verify_time',
                            title: '核销时间',
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
                            field: 'status',
                            title: '行状态',
                            searchList: {'normal': '正常', 'hidden': '隐藏'},
                            formatter: Table.api.formatter.status
                        },
                        {
                            field: 'payment_status',
                            title: '返现状态',
                            searchList: {'unpaid': '未打款', 'paid': '已打款'},
                            formatter: Table.api.formatter.status
                        },
                        {
                            field: 'operate',
                            title: '操作',
                            table: table,
                            events: Table.api.events.operate,
                            formatter: Table.api.formatter.operate,
                            buttons: [
                                {
                                    name: 'detail',
                                    text: '详情',
                                    title: '查看详情',
                                    classname: 'btn btn-xs btn-info btn-dialog',
                                    icon: 'fa fa-eye',
                                    url: 'wanlshop/voucher.rebate/detail'
                                }
                            ]
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
