define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'fast'], function ($, undefined, Backend, Table, Form, Fast) {

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

            // 统计与结算按钮 - 打开新 Tab
            $('.btn-settlement').on('click', function () {
                var url = Fast.api.fixurl('wanlshop/bdpromoter/settlement');
                Fast.api.addtabs(url, '统计与结算');
            });
        },
        detail: function () {
            Controller.api.bindevent();
        },
        stats: function () {
            // 保留空方法兼容
            Controller.api.bindevent();
        },
        settlement: function () {
            // ========== 数据概览 Tab ==========
            var loadOverview = function(type) {
                $('#overview-loading').show();
                $.ajax({
                    url: Fast.api.fixurl('wanlshop/bdpromoter/stats'),
                    type: 'GET',
                    data: {type: type},
                    dataType: 'json',
                    success: function(res) {
                        $('#overview-loading').hide();
                        if (res.code === 1) {
                            var data = res.data;
                            $('#stat-new-bd').text(data.new_bd_count);
                            $('#stat-new-shop').text(data.new_shop_bind_count);
                            $('#stat-active-bd').text(data.active_bd_count);
                            $('#stat-total-commission').text('¥' + parseFloat(data.total_commission).toFixed(2));
                            $('#stat-deduct-commission').text('¥' + parseFloat(data.deduct_commission).toFixed(2));
                            $('#stat-net-commission').text('¥' + parseFloat(data.net_commission).toFixed(2));

                            // 渲染排行榜
                            var html = '';
                            if (data.top_bd_list && data.top_bd_list.length > 0) {
                                data.top_bd_list.forEach(function(item, index) {
                                    html += '<tr>';
                                    html += '<td>' + (index + 1) + '</td>';
                                    html += '<td>' + (item.nickname || '-') + '</td>';
                                    html += '<td><code>' + (item.bd_code || '-') + '</code></td>';
                                    html += '<td class="text-success">¥' + parseFloat(item.total_commission).toFixed(2) + '</td>';
                                    html += '</tr>';
                                });
                            } else {
                                html = '<tr><td colspan="4" class="text-center text-muted">暂无数据</td></tr>';
                            }
                            $('#top-bd-list').html(html);
                        }
                    },
                    error: function() {
                        $('#overview-loading').hide();
                        Toastr.error('加载失败');
                    }
                });
            };

            // 时间筛选按钮
            $('.btn-time-filter').on('click', function() {
                $('.btn-time-filter').removeClass('active');
                $(this).addClass('active');
                loadOverview($(this).data('type'));
            });

            // 默认加载今日数据
            loadOverview('today');

            // ========== 流水明细 Tab ==========
            var loadBdList = function() {
                $.ajax({
                    url: Fast.api.fixurl('wanlshop/bdpromoter/bdList'),
                    type: 'GET',
                    dataType: 'json',
                    success: function(res) {
                        if (res.code === 1) {
                            var html = '<option value="">全部BD</option>';
                            res.data.forEach(function(item) {
                                html += '<option value="' + item.id + '">' + item.nickname + ' (' + item.bd_code + ')</option>';
                            });
                            $('#bd-select').html(html);
                        }
                    }
                });
            };

            var loadShopList = function(bdUserId) {
                if (!bdUserId) {
                    $('#shop-select').html('<option value="">全部店铺</option>');
                    return;
                }
                $.ajax({
                    url: Fast.api.fixurl('wanlshop/bdpromoter/shopListByBd'),
                    type: 'GET',
                    data: {bd_user_id: bdUserId},
                    dataType: 'json',
                    success: function(res) {
                        if (res.code === 1) {
                            var html = '<option value="">全部店铺</option>';
                            res.data.forEach(function(item) {
                                html += '<option value="' + item.id + '">' + item.name + '</option>';
                            });
                            $('#shop-select').html(html);
                        }
                    }
                });
            };

            var loadSettlementData = function() {
                $('#detail-loading').show();
                $.ajax({
                    url: Fast.api.fixurl('wanlshop/bdpromoter/settlementData'),
                    type: 'GET',
                    data: {
                        bd_user_id: $('#bd-select').val(),
                        shop_id: $('#shop-select').val(),
                        start_date: $('#start-date').val(),
                        end_date: $('#end-date').val()
                    },
                    dataType: 'json',
                    success: function(res) {
                        $('#detail-loading').hide();
                        if (res.code === 1) {
                            var data = res.data;

                            // 更新汇总数据
                            $('#summary-earn-count').text(data.summary.earn_count);
                            $('#summary-earn-amount').text('¥' + data.summary.earn_amount);
                            $('#summary-deduct-amount').text('¥' + data.summary.deduct_amount);
                            $('#summary-net-amount').text('¥' + data.summary.net_amount);

                            // 渲染明细列表
                            var html = '';
                            if (data.list && data.list.length > 0) {
                                data.list.forEach(function(item) {
                                    // 类型样式
                                    var typeClass = item.type === 'earn' ? 'success' : 'danger';
                                    var typeIcon = item.type === 'earn' ? 'fa-plus-circle' : 'fa-minus-circle';
                                    var typeLabel = item.type === 'earn'
                                        ? '<span class="text-success"><i class="fa ' + typeIcon + '"></i> ' + item.type_desc + '</span>'
                                        : '<span class="text-danger"><i class="fa ' + typeIcon + '"></i> ' + item.type_desc + '</span>';

                                    // 核销券信息
                                    var voucherHtml = '-';
                                    if (item.voucher_info) {
                                        voucherHtml = item.voucher_info + '<br><code style="font-size: 10px; color: #666;">' + item.voucher_no + '</code>';
                                    }

                                    // 金额（带正负号）
                                    var amount = parseFloat(item.commission_amount).toFixed(2);
                                    var amountHtml = item.type === 'earn'
                                        ? '<span class="text-success" style="font-weight: bold;">+' + amount + '</span>'
                                        : '<span class="text-danger" style="font-weight: bold;">-' + amount + '</span>';

                                    // 返利比例样式
                                    var rateHtml = item.rate_text && item.rate_text !== '-'
                                        ? '<span class="label label-info">' + item.rate_text + '</span>'
                                        : '<span class="text-muted">-</span>';

                                    html += '<tr>';
                                    html += '<td>' + item.createtime_text + '</td>';
                                    html += '<td>' + (item.bd_nickname || '-') + '<br><code style="font-size: 10px;">' + (item.bd_code || '-') + '</code></td>';
                                    html += '<td>' + (item.shopname || '-') + '</td>';
                                    html += '<td>' + item.period_index_text + '</td>';
                                    html += '<td>' + rateHtml + '</td>';
                                    html += '<td>' + voucherHtml + '</td>';
                                    html += '<td>' + typeLabel + '</td>';
                                    html += '<td style="font-family: monospace; color: #666;">' + item.formula + '</td>';
                                    html += '<td>' + amountHtml + '</td>';
                                    html += '</tr>';
                                });
                            } else {
                                html = '<tr><td colspan="9" class="text-center text-muted">暂无数据</td></tr>';
                            }
                            $('#settlement-list').html(html);
                        }
                    },
                    error: function() {
                        $('#detail-loading').hide();
                        Toastr.error('加载失败');
                    }
                });
            };

            // 初始化日期
            var today = new Date();
            var firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
            $('#start-date').val(firstDay.toISOString().split('T')[0]);
            $('#end-date').val(today.toISOString().split('T')[0]);

            // BD选择变化时加载店铺列表
            $('#bd-select').on('change', function() {
                loadShopList($(this).val());
            });

            // 查询按钮
            $('#btn-search').on('click', function() {
                loadSettlementData();
            });

            // 切换到流水明细 Tab 时加载数据
            var detailLoaded = false;
            $('a[data-toggle="tab"]').on('shown.bs.tab', function(e) {
                if ($(e.target).attr('href') === '#tab-detail' && !detailLoaded) {
                    loadBdList();
                    loadSettlementData();
                    detailLoaded = true;
                }
            });

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
