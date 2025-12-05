define(['jquery', 'bootstrap', 'backend', 'vue', 'echarts', 'echarts-theme'], function($, undefined, Backend, Vue, Echarts) {

    var Controller = {
        index: function() {
            // 初始化 Vue 应用
            new Vue({
                el: '#pricetrend-app',
                data: {
                    // 当前视图: 'overview' 或 'category'
                    currentView: 'overview',

                    // 全品类概览数据
                    overviewData: null,
                    overviewLoading: false,
                    overviewStartDate: '',
                    overviewEndDate: '',
                    overviewSelectedCity: '',
                    expandedCategories: [],  // 展开的分类ID列表
                    showFormula: false,  // 是否显示公式说明

                    // 城市列表
                    cityList: [],

                    // 分类详情数据
                    categories: categoriesData || [],
                    selectedCategory: '',
                    selectedCity: '',
                    specList: [],
                    selectedSpecs: [],
                    startDate: '',
                    endDate: '',

                    // 数据
                    categoryName: '',
                    chartData: [],
                    summary: {},
                    detailList: [],

                    // 状态
                    loading: false,
                    hasSearched: false,
                    activeChartIndex: 0,

                    // 图表实例
                    chart: null
                },

                mounted: function() {
                    var self = this;

                    // 设置默认日期范围（最近30天）
                    var today = new Date();
                    var thirtyDaysAgo = new Date();
                    thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);

                    this.endDate = this.formatDate(today);
                    this.startDate = this.formatDate(thirtyDaysAgo);
                    this.overviewEndDate = this.formatDate(today);
                    this.overviewStartDate = this.formatDate(thirtyDaysAgo);

                    // 初始化图表
                    this.$nextTick(function() {
                        // 初始化分类详情图表
                        var chartDom = document.getElementById('price-chart');
                        if (chartDom) {
                            self.chart = Echarts.init(chartDom, 'walden');
                        }

                        // 监听窗口变化
                        $(window).resize(function() {
                            if (self.chart) {
                                self.chart.resize();
                            }
                        });

                        // 默认加载全品类概览数据
                        self.fetchOverviewData();

                        // 加载城市列表
                        self.fetchCityList();
                    });
                },

                watch: {
                    activeChartIndex: function() {
                        this.renderChart();
                    },
                    currentView: function(newView) {
                        var self = this;
                        this.$nextTick(function() {
                            if (newView === 'category' && self.chart) {
                                self.chart.resize();
                            }
                        });
                    }
                },

                methods: {
                    // 格式化日期
                    formatDate: function(date) {
                        var year = date.getFullYear();
                        var month = ('0' + (date.getMonth() + 1)).slice(-2);
                        var day = ('0' + date.getDate()).slice(-2);
                        return year + '-' + month + '-' + day;
                    },

                    // 切换视图
                    switchView: function(view) {
                        this.currentView = view;
                    },

                    // 切换分类展开/折叠
                    toggleCategoryExpand: function(categoryId) {
                        var index = this.expandedCategories.indexOf(categoryId);
                        if (index > -1) {
                            this.expandedCategories.splice(index, 1);
                        } else {
                            this.expandedCategories.push(categoryId);
                        }
                    },

                    // 全部展开
                    expandAllCategories: function() {
                        if (this.overviewData && this.overviewData.category_stats) {
                            this.expandedCategories = this.overviewData.category_stats.map(function(cat) {
                                return cat.category_id;
                            });
                        }
                    },

                    // 全部收起
                    collapseAllCategories: function() {
                        this.expandedCategories = [];
                    },

                    // 查看分类详情
                    viewCategoryDetail: function(categoryId, categoryName) {
                        this.selectedCategory = categoryId;
                        this.categoryName = categoryName;
                        this.currentView = 'category';
                        this.loadSpecList();
                        this.fetchData();
                    },

                    // ============ 全品类概览相关方法 ============

                    // 获取全品类概览数据
                    fetchOverviewData: function() {
                        var self = this;
                        this.overviewLoading = true;

                        Fast.api.ajax({
                            url: 'wanlshop/pricetrend/getOverviewData',
                            data: {
                                start_date: this.overviewStartDate,
                                end_date: this.overviewEndDate,
                                city_name: this.overviewSelectedCity
                            }
                        }, function(data, ret) {
                            self.overviewLoading = false;
                            self.overviewData = data;
                            return false;
                        }, function(data, ret) {
                            self.overviewLoading = false;
                            Toastr.error(ret.msg);
                            return false;
                        });
                    },

                    // 获取城市列表
                    fetchCityList: function() {
                        var self = this;
                        Fast.api.ajax({
                            url: 'wanlshop/pricetrend/getCityList'
                        }, function(data, ret) {
                            self.cityList = data;
                            return false;
                        }, function(data, ret) {
                            console.log('获取城市列表失败');
                            return false;
                        });
                    },

                    // ============ 分类详情相关方法 ============

                    // 分类变更
                    onCategoryChange: function() {
                        this.selectedSpecs = [];
                        this.specList = [];
                        if (this.selectedCategory) {
                            this.loadSpecList();
                        }
                    },

                    // 加载规格列表
                    loadSpecList: function() {
                        var self = this;
                        Fast.api.ajax({
                            url: 'wanlshop/pricetrend/getSpecList',
                            data: {
                                category_id: this.selectedCategory,
                                city_name: this.selectedCity
                            }
                        }, function(data, ret) {
                            self.specList = data;
                            return false;
                        }, function(data, ret) {
                            Toastr.error(ret.msg);
                            return false;
                        });
                    },

                    // 切换规格选择
                    toggleSpec: function(spec) {
                        var index = this.selectedSpecs.indexOf(spec);
                        if (index > -1) {
                            this.selectedSpecs.splice(index, 1);
                        } else {
                            this.selectedSpecs.push(spec);
                        }
                    },

                    // 获取数据
                    fetchData: function() {
                        if (!this.selectedCategory) {
                            Toastr.warning('请选择分类');
                            return;
                        }

                        var self = this;
                        this.loading = true;
                        this.hasSearched = true;

                        Fast.api.ajax({
                            url: 'wanlshop/pricetrend/getTrendData',
                            data: {
                                category_id: this.selectedCategory,
                                specs: this.selectedSpecs.join(','),
                                start_date: this.startDate,
                                end_date: this.endDate,
                                city_name: this.selectedCity
                            }
                        }, function(data, ret) {
                            self.loading = false;
                            self.categoryName = data.category_name;
                            self.chartData = data.chart_data;
                            self.summary = data.summary;
                            self.activeChartIndex = 0;

                            self.$nextTick(function() {
                                // 确保图表容器已渲染
                                if (!self.chart) {
                                    var chartDom = document.getElementById('price-chart');
                                    if (chartDom) {
                                        self.chart = Echarts.init(chartDom, 'walden');
                                    }
                                }
                                self.renderChart();
                            });

                            // 同时加载详情列表
                            self.fetchDetailList();

                            return false;
                        }, function(data, ret) {
                            self.loading = false;
                            Toastr.error(ret.msg);
                            return false;
                        });
                    },

                    // 获取详情列表
                    fetchDetailList: function() {
                        var self = this;
                        var spec = '';
                        if (this.chartData.length > 0 && this.chartData[this.activeChartIndex]) {
                            spec = this.chartData[this.activeChartIndex].spec;
                        }

                        Fast.api.ajax({
                            url: 'wanlshop/pricetrend/getDetailList',
                            data: {
                                category_id: this.selectedCategory,
                                spec: spec,
                                city_name: this.selectedCity
                            }
                        }, function(data, ret) {
                            self.detailList = data;
                            return false;
                        }, function(data, ret) {
                            Toastr.error(ret.msg);
                            return false;
                        });
                    },

                    // 渲染图表
                    renderChart: function() {
                        if (!this.chart || !this.chartData || this.chartData.length === 0) {
                            return;
                        }

                        var data = this.chartData[this.activeChartIndex];
                        if (!data) {
                            return;
                        }

                        var dates = data.dates;
                        var platformPrices = data.platform_prices;
                        var merchantAvgPrices = data.merchant_avg_prices;
                        var merchantMinPrices = data.merchant_min_prices;
                        var merchantMaxPrices = data.merchant_max_prices;

                        var option = {
                            title: {
                                text: data.spec + ' 规格价格走势',
                                left: 'center',
                                textStyle: {
                                    fontSize: 14,
                                    fontWeight: 500,
                                    color: '#333'
                                }
                            },
                            tooltip: {
                                trigger: 'axis',
                                backgroundColor: 'rgba(50, 50, 50, 0.9)',
                                borderWidth: 0,
                                textStyle: {
                                    color: '#fff'
                                },
                                formatter: function(params) {
                                    var date = params[0].axisValue;
                                    var html = '<div style="font-weight: 600; margin-bottom: 8px;">' + date + '</div>';

                                    params.forEach(function(item) {
                                        if (item.value !== null && item.value !== '-' && item.seriesName !== '商家价格区间' && item.seriesName !== '价格区间') {
                                            var color = item.color;
                                            if (typeof color === 'object') {
                                                color = '#f39c12';
                                            }
                                            html += '<div style="display: flex; justify-content: space-between; align-items: center; margin: 4px 0;">';
                                            html += '<span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: ' + color + '; margin-right: 8px;"></span>';
                                            html += '<span style="flex: 1;">' + item.seriesName + '</span>';
                                            html += '<span style="font-weight: 600; margin-left: 15px;">￥' + item.value + '</span>';
                                            html += '</div>';
                                        }
                                    });

                                    return html;
                                }
                            },
                            legend: {
                                data: ['平台价格', '商家均价'],
                                bottom: 10,
                                textStyle: {
                                    color: '#666'
                                }
                            },
                            grid: {
                                left: '3%',
                                right: '4%',
                                bottom: '15%',
                                top: '15%',
                                containLabel: true
                            },
                            toolbox: {
                                show: true,
                                right: 20,
                                feature: {
                                    dataZoom: {
                                        yAxisIndex: 'none',
                                        title: {
                                            zoom: '区域缩放',
                                            back: '还原'
                                        }
                                    },
                                    magicType: {
                                        type: ['line', 'bar'],
                                        title: {
                                            line: '折线图',
                                            bar: '柱状图'
                                        }
                                    },
                                    restore: {
                                        title: '还原'
                                    },
                                    saveAsImage: {
                                        title: '保存图片'
                                    }
                                }
                            },
                            dataZoom: [
                                {
                                    type: 'inside',
                                    start: 0,
                                    end: 100
                                },
                                {
                                    start: 0,
                                    end: 100,
                                    handleSize: '80%',
                                    handleStyle: {
                                        color: '#fff',
                                        shadowBlur: 3,
                                        shadowColor: 'rgba(0, 0, 0, 0.2)',
                                        shadowOffsetX: 2,
                                        shadowOffsetY: 2
                                    }
                                }
                            ],
                            xAxis: {
                                type: 'category',
                                boundaryGap: false,
                                data: dates,
                                axisLine: {
                                    lineStyle: {
                                        color: '#ddd'
                                    }
                                },
                                axisLabel: {
                                    color: '#666',
                                    formatter: function(value) {
                                        return value.substring(5); // 只显示 MM-DD
                                    }
                                }
                            },
                            yAxis: {
                                type: 'value',
                                name: '价格 (元)',
                                nameTextStyle: {
                                    color: '#666'
                                },
                                axisLine: {
                                    show: false
                                },
                                axisTick: {
                                    show: false
                                },
                                axisLabel: {
                                    color: '#666',
                                    formatter: '￥{value}'
                                },
                                splitLine: {
                                    lineStyle: {
                                        color: '#f0f0f0'
                                    }
                                }
                            },
                            series: [
                                {
                                    name: '商家价格区间',
                                    type: 'line',
                                    stack: 'range-min',
                                    smooth: true,
                                    symbol: 'none',
                                    lineStyle: {
                                        opacity: 0
                                    },
                                    areaStyle: {
                                        color: 'transparent'
                                    },
                                    data: merchantMinPrices
                                },
                                {
                                    name: '价格区间',
                                    type: 'line',
                                    stack: 'range-min',
                                    smooth: true,
                                    symbol: 'none',
                                    lineStyle: {
                                        opacity: 0
                                    },
                                    areaStyle: {
                                        color: 'rgba(243, 156, 18, 0.15)'
                                    },
                                    data: this.calculateRangeData(merchantMinPrices, merchantMaxPrices)
                                },
                                {
                                    name: '平台价格',
                                    type: 'line',
                                    smooth: true,
                                    symbol: 'circle',
                                    symbolSize: 6,
                                    lineStyle: {
                                        color: '#3c8dbc',
                                        width: 3
                                    },
                                    itemStyle: {
                                        color: '#3c8dbc',
                                        borderColor: '#fff',
                                        borderWidth: 2
                                    },
                                    emphasis: {
                                        itemStyle: {
                                            borderWidth: 3
                                        }
                                    },
                                    data: platformPrices,
                                    markPoint: {
                                        data: [
                                            { type: 'max', name: '最高价' },
                                            { type: 'min', name: '最低价' }
                                        ],
                                        label: {
                                            formatter: '￥{c}'
                                        }
                                    },
                                    markLine: {
                                        data: [
                                            { type: 'average', name: '平均价' }
                                        ],
                                        label: {
                                            formatter: '均价: ￥{c}'
                                        }
                                    }
                                },
                                {
                                    name: '商家均价',
                                    type: 'line',
                                    smooth: true,
                                    symbol: 'circle',
                                    symbolSize: 6,
                                    lineStyle: {
                                        color: '#f39c12',
                                        width: 3
                                    },
                                    itemStyle: {
                                        color: '#f39c12',
                                        borderColor: '#fff',
                                        borderWidth: 2
                                    },
                                    emphasis: {
                                        itemStyle: {
                                            borderWidth: 3
                                        }
                                    },
                                    data: merchantAvgPrices,
                                    markLine: {
                                        data: [
                                            { type: 'average', name: '平均价' }
                                        ],
                                        lineStyle: {
                                            color: '#f39c12'
                                        },
                                        label: {
                                            formatter: '均价: ￥{c}'
                                        }
                                    }
                                }
                            ]
                        };

                        this.chart.setOption(option, true);
                        this.chart.resize();
                    },

                    // 计算区间差值（用于堆叠面积图）
                    calculateRangeData: function(minData, maxData) {
                        var result = [];
                        for (var i = 0; i < minData.length; i++) {
                            if (minData[i] !== null && maxData[i] !== null) {
                                result.push(maxData[i] - minData[i]);
                            } else {
                                result.push(null);
                            }
                        }
                        return result;
                    }
                }
            });
        }
    };

    return Controller;
});
