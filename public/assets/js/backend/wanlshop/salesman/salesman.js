define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'wanlshop/salesman/salesman/index' + location.search,
                    add_url: 'wanlshop/salesman/salesman/add',
                    edit_url: 'wanlshop/salesman/salesman/edit',
                    del_url: 'wanlshop/salesman/salesman/del',
                    detail_url: 'wanlshop/salesman/salesman/detail',
                    multi_url: 'wanlshop/salesman/salesman/multi',
                    table: 'salesman',
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'id',
                sortOrder: 'desc',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: __('ID'), sortable: true},
                        {field: 'user.nickname', title: __('用户昵称'), operate: 'LIKE'},
                        {field: 'user.mobile', title: __('手机号'), operate: 'LIKE'},
                        {field: 'user.bonus_level', title: __('返利等级'), formatter: function(value) {
                            return 'Lv' + (value || 0);
                        }},
                        {field: 'stats.invite_user_verified', title: __('邀请用户(核销)')},
                        {field: 'stats.invite_shop_verified', title: __('邀请商家(核销)')},
                        {field: 'stats.total_rebate_amount', title: __('累计返利'), formatter: function(value) {
                            return '¥' + (value || '0.00');
                        }},
                        {field: 'status_text', title: __('状态'), searchList: {"normal": __('正常'), "disabled": __('禁用')}, formatter: Table.api.formatter.status},
                        {field: 'createtime', title: __('创建时间'), operate:'RANGE', addclass:'datetimerange', autocomplete:false, formatter: Table.api.formatter.datetime},
                        {field: 'operate', title: __('操作'), table: table, events: Table.api.events.operate, formatter: function(value, row, index) {
                            var that = $.extend({}, this);
                            var table = $(that.table).clone(true);
                            // 添加详情按钮
                            $(table).data("operate-detail", true);
                            that.table = table;
                            return Table.api.formatter.operate.call(that, value, row, index);
                        }, buttons: [
                            {
                                name: 'detail',
                                text: __('详情'),
                                title: __('业务员详情'),
                                classname: 'btn btn-xs btn-info btn-dialog',
                                icon: 'fa fa-eye',
                                url: $.fn.bootstrapTable.defaults.extend.detail_url,
                                extend: 'data-area=\'["90%","90%"]\''
                            }
                        ]}
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
            // 详情页不需要特殊处理
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };
    return Controller;
});
