define(['jquery', 'bootstrap', 'backend', 'table', 'form'], function ($, undefined, Backend, Table, Form) {

    var Controller = {
        index: function () {
            // 初始化表格参数配置
            Table.api.init({
                extend: {
                    index_url: 'wanlshop/bdpromoter/index',
                    detail_url: 'wanlshop/bdpromoter/detail',
                    table: 'bd_promoter'
                }
            });

            var table = $("#table");

            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                pk: 'id',
                sortName: 'bd_apply_time',
                sortOrder: 'desc',
                columns: [
                    [
                        {checkbox: true},
                        {field: 'id', title: __('ID'), sortable: true},
                        {
                            field: 'avatar',
                            title: __('头像'),
                            events: Table.api.events.image,
                            formatter: Table.api.formatter.image,
                            operate: false
                        },
                        {field: 'nickname', title: __('昵称'), operate: 'LIKE'},
                        {field: 'bd_code', title: __('BD推广码'), operate: 'LIKE', formatter: function(value) {
                            return '<code>' + value + '</code>';
                        }},
                        {field: 'mobile', title: __('手机号'), operate: 'LIKE'},
                        {field: 'shop_count', title: __('邀请店铺数'), operate: false, sortable: true},
                        {field: 'period_index', title: __('当前周期'), operate: false, formatter: function(value) {
                            return value ? '第' + value + '周期' : '-';
                        }},
                        {field: 'current_rate_text', title: __('当前比例'), operate: false, formatter: function(value, row) {
                            if (row.current_rate > 0) {
                                return '<span class="label label-success">' + value + '</span>';
                            } else {
                                return '<span class="label label-default">' + value + '</span>';
                            }
                        }},
                        {field: 'total_commission', title: __('累计佣金'), operate: false, sortable: true, formatter: function(value) {
                            return '<span class="text-success">¥' + parseFloat(value).toFixed(2) + '</span>';
                        }},
                        {field: 'bd_apply_time_text', title: __('申请时间'), operate: false, sortable: true},
                        {
                            field: 'operate',
                            title: __('Operate'),
                            table: table,
                            events: Table.api.events.operate,
                            formatter: Table.api.formatter.operate,
                            buttons: [
                                {
                                    name: 'detail',
                                    text: __('详情'),
                                    title: __('BD详情'),
                                    classname: 'btn btn-xs btn-info btn-addtabs',
                                    icon: 'fa fa-eye',
                                    url: function(row) {
                                        return 'wanlshop/bdpromoter/detail?ids=' + row.id;
                                    }
                                }
                            ]
                        }
                    ]
                ]
            });

            // 为表格绑定事件
            Table.api.bindevent(table);

            // 统计报表按钮
            $(document).on('click', '.btn-stats', function() {
                Fast.api.open('wanlshop/bdpromoter/stats', 'BD统计报表', {area: ['90%', '85%']});
            });
        },
        detail: function () {
            Controller.api.bindevent();
        },
        stats: function () {
            Controller.api.bindevent();
        },
        api: {
            bindevent: function () {
                Form.api.bindevent($("form[role=form]"));
            }
        }
    };
    return Controller;
});
