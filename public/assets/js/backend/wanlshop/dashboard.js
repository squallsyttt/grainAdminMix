define(['jquery', 'bootstrap', 'backend', 'table', 'vue', 'echarts', 'echarts-theme'], function($, undefined, Backend, Table, Vue, Echarts) {

	var Controller = {
		index: function() {
			// 载入Vue
			new Vue({
				el:'#wanlshop',
				data: {
					refundList: Config.servicesRefundList,
					shopAuthList: Config.shopAuthList,
					refundState: ['申请退款','卖家同意','卖家拒绝','申请平台','成功退款','退款已关闭','已提交物流'],
					shopState: ['个人','企业','旗舰'],
					shopVerify: ['提交资质','提交店铺','提交审核','通过','未通过'],
				},
				methods: {
					shopAgree(index) {
						var app = this;
						layer.confirm(`确定 同意 ${app.shopAuthList[index].shopname} 店铺入驻？`, {
							btn: ['确定','取消']
						}, function(){
							Fast.api.ajax({
								url: "wanlshop/auth/agree",
								data: {
									ids: app.shopAuthList[index].id
								}
							}, function(data, ret) {
								app.shopAuthList[index].verify = 3;
								layer.msg('已同意入驻申请', {icon: 1});
								return false;
							});
						});
					},
					shopRefuse(index) {
						var app = this;
						layer.prompt({title: '请输入拒绝入驻理由', formType: 2}, function(text, key){
							Fast.api.ajax({
								url: `wanlshop/auth/refuse/ids/${app.shopAuthList[index].id}`,
								data: {
									row: {
										refuse: text
									}
								}
							}, function(data, ret) {
								layer.close(key);
								app.shopAuthList[index].verify = 4;
								layer.msg('已拒绝入驻申请', {icon: 1});
								return false;
							});
						});
					},
					refundAgree(index) {
						var app = this;
						layer.confirm(`确定 买家符合退款，直接退款给买家？`, {
							btn: ['确定','取消']
						}, function(){
							Fast.api.ajax({
								url: "wanlshop/refund/agree",
								data: {
									ids: app.refundList[index].id
								}
							}, function(data, ret) {
								app.refundList[index].state = 4;
								layer.msg('已同意退款申请', {icon: 1});
								return false;
							});
						});
					},
					refundRefuse(index) {
						var app = this;
						layer.prompt({title: '请输入拒绝退款理由', formType: 2}, function(text, key){
							console.log(text);
							Fast.api.ajax({
								url: `wanlshop/refund/refuse/ids/${app.refundList[index].id}`,
								data: {
									row: {
										refund_content: text
									}
								}
							}, function(data, ret) {
								layer.close(key);
								app.refundList[index].state = 5;
								layer.msg('已拒绝退款申请', {icon: 1});
								return false;
							});
						});
					},
					shopDetails(index) {
						Fast.api.open(`wanlshop/auth/detail/ids/${this.shopAuthList[index].id}`, "查看入驻信息", {});
					},
					refundDetails(index) {
						Fast.api.open(`wanlshop/refund/detail/ids/${this.refundList[index].id}`, "查看退款", {});
					}
				}
			});

			// 趋势图相关
			var charts = {
				order: null,
				verify: null,
				refund: null,
				users: null
			};

			// 初始化图表
			function initCharts() {
				var chartOrder = document.getElementById('chart-order');
				var chartVerify = document.getElementById('chart-verify');
				var chartRefund = document.getElementById('chart-refund');
				var chartUsers = document.getElementById('chart-users');

				if (chartOrder) charts.order = Echarts.init(chartOrder, 'walden');
				if (chartVerify) charts.verify = Echarts.init(chartVerify, 'walden');
				if (chartRefund) charts.refund = Echarts.init(chartRefund, 'walden');
				if (chartUsers) charts.users = Echarts.init(chartUsers, 'walden');

				// 窗口resize时自动调整图表大小
				$(window).resize(function() {
					Object.values(charts).forEach(function(chart) {
						if (chart) chart.resize();
					});
				});
			}

			// 加载趋势数据
			function loadTrendData() {
				var days = $('#trend-days').val() || 30;
				Fast.api.ajax({
					url: 'wanlshop/dashboard/getTrendData',
					data: { days: days }
				}, function(data, ret) {
					renderCharts(data);
					return false;
				}, function(data, ret) {
					layer.msg(ret.msg || '加载失败', {icon: 2});
					return false;
				});
			}

			// 渲染所有图表
			function renderCharts(data) {
				var dates = data.dates || [];
				// 格式化日期显示（只显示月-日）
				var shortDates = dates.map(function(d) { return d.substring(5); });

				// 图表1：每日流水 / 订单数（双Y轴）
				if (charts.order) {
					charts.order.setOption({
						tooltip: {
							trigger: 'axis',
							axisPointer: { type: 'cross' }
						},
						legend: { data: ['流水金额', '订单数'], bottom: 0 },
						grid: { left: '3%', right: '4%', bottom: '15%', top: '10%', containLabel: true },
						xAxis: {
							type: 'category',
							data: shortDates,
							axisLabel: { rotate: 45, fontSize: 11 }
						},
						yAxis: [
							{ type: 'value', name: '金额(元)', axisLabel: { formatter: '￥{value}' } },
							{ type: 'value', name: '订单数', axisLabel: { formatter: '{value}单' } }
						],
						series: [
							{
								name: '流水金额',
								type: 'line',
								smooth: true,
								data: data.orderAmount,
								areaStyle: { opacity: 0.3 },
								itemStyle: { color: '#3c8dbc' }
							},
							{
								name: '订单数',
								type: 'bar',
								yAxisIndex: 1,
								data: data.orderCount,
								itemStyle: { color: '#f39c12', opacity: 0.7 }
							}
						]
					});
				}

				// 图表2：每日核销金额 / 核销数量
				if (charts.verify) {
					charts.verify.setOption({
						tooltip: {
							trigger: 'axis',
							axisPointer: { type: 'cross' }
						},
						legend: { data: ['核销金额', '核销数量'], bottom: 0 },
						grid: { left: '3%', right: '4%', bottom: '15%', top: '10%', containLabel: true },
						xAxis: {
							type: 'category',
							data: shortDates,
							axisLabel: { rotate: 45, fontSize: 11 }
						},
						yAxis: [
							{ type: 'value', name: '金额(元)', axisLabel: { formatter: '￥{value}' } },
							{ type: 'value', name: '数量', axisLabel: { formatter: '{value}张' } }
						],
						series: [
							{
								name: '核销金额',
								type: 'line',
								smooth: true,
								data: data.verifyAmount,
								areaStyle: { opacity: 0.3 },
								itemStyle: { color: '#00a65a' }
							},
							{
								name: '核销数量',
								type: 'bar',
								yAxisIndex: 1,
								data: data.verifyCount,
								itemStyle: { color: '#00c0ef', opacity: 0.7 }
							}
						]
					});
				}

				// 图表3：每日退款金额
				if (charts.refund) {
					charts.refund.setOption({
						tooltip: {
							trigger: 'axis',
							formatter: function(params) {
								var d = params[0];
								return d.name + '<br/>' + d.marker + d.seriesName + ': ￥' + d.value;
							}
						},
						grid: { left: '3%', right: '4%', bottom: '15%', top: '10%', containLabel: true },
						xAxis: {
							type: 'category',
							data: shortDates,
							axisLabel: { rotate: 45, fontSize: 11 }
						},
						yAxis: {
							type: 'value',
							name: '金额(元)',
							axisLabel: { formatter: '￥{value}' }
						},
						series: [{
							name: '退款金额',
							type: 'line',
							smooth: true,
							data: data.refundAmount,
							areaStyle: { opacity: 0.3 },
							itemStyle: { color: '#dd4b39' }
						}]
					});
				}

				// 图表4：每日新增用户
				if (charts.users) {
					charts.users.setOption({
						tooltip: {
							trigger: 'axis',
							formatter: function(params) {
								var d = params[0];
								return d.name + '<br/>' + d.marker + d.seriesName + ': ' + d.value + '人';
							}
						},
						grid: { left: '3%', right: '4%', bottom: '15%', top: '10%', containLabel: true },
						xAxis: {
							type: 'category',
							data: shortDates,
							axisLabel: { rotate: 45, fontSize: 11 }
						},
						yAxis: {
							type: 'value',
							name: '用户数',
							axisLabel: { formatter: '{value}人' }
						},
						series: [{
							name: '新增用户',
							type: 'line',
							smooth: true,
							data: data.newUsers,
							areaStyle: { opacity: 0.3 },
							itemStyle: { color: '#9b59b6' }
						}]
					});
				}
			}

			// 绑定事件
			$('#refresh-trend').on('click', function() {
				loadTrendData();
			});
			$('#trend-days').on('change', function() {
				loadTrendData();
			});

			// 页面加载完成后初始化
			$(function() {
				initCharts();
				loadTrendData();
			});
		},
		api: {
			bindevent: function() {
				Form.api.bindevent($("form[role=form]"));
			}
		}
	};
	return Controller;
});
