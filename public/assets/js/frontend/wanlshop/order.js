define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'template', 'jquery-jqprint', 'jquery-migrate'], function ($, undefined, Backend, Table, Form, Template, Jqprint, Migrate) {
    var Controller = {
        index: function () {
			// return chatFun; 1.0.8升级 注释不明使用方式
            Table.api.init({
                extend: {
                    index_url: 'wanlshop/order/index' + location.search,
                    add_url: '',
                    edit_url: '',
                    del_url: '',
                    multi_url: '',
                    table: 'wanlshop_voucher_verification',
                }
            });
            var table = $("#table");
			var $dataList = $("#data-list");
            var filterData = {};
            var $filterForm = $(".wanl-filter form");
            var $timeInput = $filterForm.find('input[name="createtime"]');
            var defaultRange = $.trim($timeInput.val());
            var $quickRange = $(".wanl-quick-range");
            var quickSearch = '';
            var $searchInput = $(".wanl-search-input");
            var settlementState = 'all'; // 结算状态筛选
            if (defaultRange) {
                filterData.createtime = defaultRange;
            }
            var formatRangeValue = function (range) {
                if (!range || range.length !== 2) {
                    return '';
                }
                return range[0].format('YYYY-MM-DD HH:mm:ss') + ' - ' + range[1].format('YYYY-MM-DD HH:mm:ss');
            };
            var presetRanges = {
                today: function () {
                    var now = Moment();
                    return [now.clone().startOf('day'), now.clone().endOf('day')];
                },
                recent7: function () {
                    var now = Moment();
                    return [now.clone().subtract(6, 'day').startOf('day'), now.clone().endOf('day')];
                },
                recent30: function () {
                    var now = Moment();
                    return [now.clone().subtract(29, 'day').startOf('day'), now.clone().endOf('day')];
                },
                month: function () {
                    var now = Moment();
                    return [now.clone().startOf('month'), now.clone().endOf('month')];
                },
                lastMonth: function () {
                    var lastMonth = Moment().subtract(1, 'month');
                    return [lastMonth.clone().startOf('month'), lastMonth.clone().endOf('month')];
                }
            };
            var highlightPreset = function (value) {
                if (!$quickRange.length) {
                    return;
                }
                $quickRange.find('[data-range]').removeClass('active');
                var current = $.trim(value || '');
                if (!current) {
                    return;
                }
                $.each(presetRanges, function (key, builder) {
                    if (formatRangeValue(builder()) === current) {
                        $quickRange.find('[data-range="' + key + '"]').addClass('active');
                        return false;
                    }
                });
            };
            Template.helper("Moment", Moment);
            Template.helper("cdnurl", function(image) {
                return Fast.api.cdnurl(image);
            });
            highlightPreset(defaultRange);
            // 初始化表格
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
				toolbar: '',
				search: false,
				commonSearch: false,
				showColumns: false,
				showToggle: false,
				showExport: false,
				templateView: true,
                pk: 'id',
                sortName: 'createtime',
				sortOrder: 'desc',
				queryParams: function (params) {
					var filter = params.filter ? (typeof params.filter === 'object' ? params.filter : JSON.parse(params.filter)) : {};
					var op = params.op ? (typeof params.op === 'object' ? params.op : JSON.parse(params.op)) : {};
					if (filterData.createtime) {
						filter.createtime = filterData.createtime;
						op.createtime = 'RANGE';
					} else {
						delete filter.createtime;
						delete op.createtime;
					}
					if (quickSearch) {
						params.search = quickSearch;
					} else {
						delete params.search;
					}
					// 添加结算状态筛选参数
					if (settlementState && settlementState !== 'all') {
						params.settlement_state = settlementState;
					} else {
						delete params.settlement_state;
					}
					params.filter = JSON.stringify(filter);
					params.op = JSON.stringify(op);
					return params;
				},
                columns: [
                    [
						{checkbox: true},
						{field: 'voucher_no', title: __('核销码')},
						{field: 'shop_goods_title', title: __('商品名称'), align: 'left', formatter: function (value, row) {
							return value || (row.voucher ? row.voucher.goods_title : '');
						}},
						{field: 'shop_name', title: __('核销店铺'), align: 'left', formatter: Table.api.formatter.search},
						{field: 'user.nickname', title: __('用户昵称'), align: 'left', formatter: Table.api.formatter.search},
						{field: 'supply_price', title: __('供货价'), operate: 'BETWEEN'},
						{field: 'verify_method', title: __('核销方式'), searchList: {"code":__('验证码'),"scan":__('扫码')}, formatter: Table.api.formatter.normal},
						{field: 'createtime', title: __('核销时间'), operate:'RANGE', addclass:'datetimerange', formatter: Table.api.formatter.datetime}
                    ]
                ]
            });
            // 为表格绑定事件
            Table.api.bindevent(table);
			//点击详情
			$(document).on("click", ".detail[data-id]", function () {
			    Backend.api.open('wanlshop/order/detail/id/' + $(this).data('id'), __('查看详情'),{area:['1200px', '780px']});
			});
			var applyFilter = function (range) {
				var value = $.trim(range || '');
				if (value) {
					filterData.createtime = value;
				} else {
					delete filterData.createtime;
				}
				highlightPreset(value);
				table.bootstrapTable('refresh', {pageNumber: 1});
			};
			var applySearch = function (keyword) {
				quickSearch = $.trim(keyword || '');
				table.bootstrapTable('refresh', {pageNumber: 1});
			};
			$(document).on("click", ".wanl-table-actions .btn-refresh", function () {
				table.bootstrapTable('refresh');
			});
			$(document).on("click", ".wanl-search-btn", function () {
				applySearch($searchInput.val());
			});
			$searchInput.on("keypress", function (event) {
				if (event.which === 13) {
					event.preventDefault();
					applySearch($(this).val());
				}
			});
			$(document).on("click", ".searchit[data-value]", function () {
				var value = $(this).data("value") || '';
				$searchInput.val(value);
				applySearch(value);
			});
			$(document).on("click", ".wanl-quick-range [data-range]", function () {
				var key = $(this).data("range");
				var builder = presetRanges[key];
				if (!builder) {
					return;
				}
				var rangeValue = formatRangeValue(builder());
				$timeInput.val(rangeValue);
				applyFilter(rangeValue);
			});
			// 结算状态筛选Tab点击事件
			$(document).on("click", "#settlement-state-tabs .state-tab", function () {
				var $this = $(this);
				var state = $this.data("state");
				// 更新Tab样式
				$("#settlement-state-tabs .state-tab").removeClass("active");
				$this.addClass("active");
				// 更新筛选状态并刷新表格
				settlementState = state;
				table.bootstrapTable('refresh', {pageNumber: 1});
			});
			// 查询
			$(document).on("click", ".btn-filter", function () {
				applyFilter($.trim($timeInput.val()));
			});
			// 重置
			$(document).on("click", ".btn-reset", function () {
				if ($filterForm.length) {
					$filterForm[0].reset();
				}
				if (defaultRange) {
					$timeInput.val(defaultRange);
				} else {
					$timeInput.val('');
				}
				// 重置结算状态筛选
				settlementState = 'all';
				$("#settlement-state-tabs .state-tab").removeClass("active");
				$("#settlement-state-tabs .state-tab[data-state='all']").addClass("active");
				applyFilter($.trim($timeInput.val()));
			});
			// 数据加载完成后渲染到自定义表格
			table.on('load-success.bs.table', function (event, data) {
				var rows = data && data.rows ? data.rows : [];
				var html = '';
				var tpl = Template('itemtpl');
				for (var i = 0; i < rows.length; i++) {
					html += tpl({item: rows[i], i: i});
				}
				$dataList.html(html || '<tr><td colspan="8" style="text-align:center;color:#6b7280;padding:40px;">暂无数据</td></tr>');
				// 刷新统计面板
				var stats = data && data.stats ? data.stats : null;
				if (stats) {
					$('[data-field="today_count"]').text(stats.today_count !== undefined ? stats.today_count : 0);
					$('[data-field="today_amount"]').text(stats.today_amount !== undefined ? stats.today_amount : 0);
					$('[data-field="month_count"]').text(stats.month_count !== undefined ? stats.month_count : 0);
					$('[data-field="month_amount"]').text(stats.month_amount !== undefined ? stats.month_amount : 0);
				}
			});
        },
		comment: function () {
			// 初始化表格参数配置
			Table.api.init({
			    extend: {
			        index_url: 'wanlshop/comment/index' + location.search,
			        add_url: '',
			        edit_url: '',
			        del_url: '',
			        multi_url: '',
			        table: 'wanlshop_goods_comment',
			    }
			});
			
			var table = $("#table");
			
			// 初始化表格
			table.bootstrapTable({
			    url: $.fn.bootstrapTable.defaults.extend.index_url,
			    pk: 'id',
			    sortName: 'id',
				searchFormVisible:true,
				fixedColumns: true,
                fixedRightNumber: 1,
			    columns: [
			        [
			            {checkbox: true},
			            {field: 'id', title: __('Id')},
						{field: 'user.nickname', title: __('User.nickname'), formatter: Table.api.formatter.search},
						{field: 'goods.title', title: __('goods.title'), searchable: false},
						{field: 'order_type', title: __('Order_type'), searchList: {"goods":__('Order_type goods'),"groups":__('Order_type groups'),"seckill":__('Order_type seckill')}, formatter: Table.api.formatter.normal},
			            {field: 'state', title: __('States'), searchList: {"0":__('States 0'),"1":__('States 1'),"2":__('States 2')}, formatter: Table.api.formatter.normal},
			            {field: 'images', title: __('Images'), events: Table.api.events.image, formatter: Table.api.formatter.images},
			            {field: 'score', title: __('Score'), operate:'BETWEEN'},
			            {field: 'score_describe', title: __('Score_describe'), operate:'BETWEEN'},
			            {field: 'score_service', title: __('Score_service'), operate:'BETWEEN'},
			            {field: 'score_deliver', title: __('Score_deliver'), operate:'BETWEEN'},
			            {field: 'score_logistics', title: __('Score_logistics'), operate:'BETWEEN'},
			            {field: 'createtime', title: __('Createtime'), operate:'RANGE', addclass:'datetimerange', formatter: Table.api.formatter.datetime},
			            {field: 'updatetime', title: __('Updatetime'), operate:'RANGE', addclass:'datetimerange', formatter: Table.api.formatter.datetime},
			            {field: 'status', title: __('Status'), searchList: {"normal":__('Normal'),"hidden":__('Hidden')}, formatter: Table.api.formatter.status},
			            {field: 'operate', title: __('Operate'), table: table, events: Table.api.events.operate,
							buttons: [
								{
									name: 'detail',
									title: function (row) {
										return `查看 ${__('Order_type ' + row.order_type)} 评论`;
									},
									classname: 'btn btn-xs btn-info btn-dialog',
									icon: 'fa fa-eye',
									url: 'wanlshop/comment/detail'
								},
							],formatter: Table.api.formatter.operate,
						}
			        ]
			    ]
			});
			
			// 为表格绑定事件
			Table.api.bindevent(table);
		},
		invoice: function () {
			$(document).on("click", ".btn-embossed", function () {
				$("#print_html").jqprint();
				$(".btn-embossed").text("初始化打印..");
				setTimeout(function() { 
					parent.Layer.closeAll();
				},1000);
			});
        },
		relative: function () {
			
		},
		detail: function () {
			// 查询物流状态
			$(document).on("click", ".kuaidisub[data-id]", function () {
			    Backend.api.open('wanlshop/order/relative/id/' + $(this).data('id'), __('快递查询'),{area:['800px', '600px']});
			});
		},
        delivery: function () {
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
