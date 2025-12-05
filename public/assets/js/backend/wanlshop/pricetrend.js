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
                    expandedSkus: [],  // 展开的SKU ID列表（格式：categoryId_skuId）
                    showFormula: false,  // 是否显示公式说明

                    // 城市列表（概览用）
                    cityList: [],

                    // 分类详情数据
                    categories: categoriesData || [],
                    selectedCategory: '',
                    selectedCity: '',  // 查询时用的城市筛选
                    specList: [],
                    selectedSpecs: [],
                    startDate: '',
                    endDate: '',

                    // 详情视图 - 历史价格图表数据
                    categoryName: '',
                    chartDataByCity: {},  // 按城市组织的图表数据
                    detailCityList: [],   // 详情视图的城市列表
                    detailSelectedCity: '',  // 详情视图当前选中的城市
                    detailSpecList: [],   // 当前城市的规格列表
                    activeSpecIndex: 0,   // 当前选中的规格索引
                    currentStats: null,   // 当前选中城市+规格的统计数据
                    currentShopList: [],  // 当前城市+规格的店铺列表
                    currentMerchants: [], // 当前城市+规格的商家SKU数据
                    selectedShops: [],    // 选中要显示的店铺ID
                    showMerchantAvg: true, // 是否显示商家均价线
                    detailList: [],

                    // 兼容旧数据（如果需要）
                    chartData: [],
                    summary: {},

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
                    activeSpecIndex: function() {
                        this.updateCurrentStats();
                        this.renderChart();
                    },
                    detailSelectedCity: function() {
                        this.onDetailCityChange();
                    },
                    selectedShops: {
                        handler: function() {
                            this.renderChart();
                        },
                        deep: true
                    },
                    showMerchantAvg: function() {
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

                    // 切换SKU展开/折叠（查看商家报价）
                    toggleSkuExpand: function(skuKey) {
                        var index = this.expandedSkus.indexOf(skuKey);
                        if (index > -1) {
                            this.expandedSkus.splice(index, 1);
                        } else {
                            this.expandedSkus.push(skuKey);
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

                    // 定位到指定SKU（从价差汇总表点击）
                    locateToSku: function(categoryId, skuId) {
                        var self = this;
                        // 1. 展开对应的分类
                        if (!this.expandedCategories.includes(categoryId)) {
                            this.expandedCategories.push(categoryId);
                        }
                        // 2. 展开对应的SKU
                        var skuKey = categoryId + '_' + skuId;
                        if (!this.expandedSkus.includes(skuKey)) {
                            this.expandedSkus.push(skuKey);
                        }
                        // 3. 滚动到对应位置
                        this.$nextTick(function() {
                            var element = document.querySelector('.category-card');
                            if (element) {
                                // 找到对应分类的卡片
                                var cards = document.querySelectorAll('.category-card');
                                for (var i = 0; i < cards.length; i++) {
                                    var card = cards[i];
                                    // 简单方式：滚动到分类SKU明细区域
                                    var section = document.querySelector('.category-sku-section');
                                    if (section) {
                                        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
                                    }
                                    break;
                                }
                            }
                        });
                    },

                    // 从概览跳转到分类详情
                    jumpToDetail: function(categoryId, cityName, spec) {
                        var self = this;
                        // 设置分类、城市、规格
                        this.selectedCategory = categoryId;
                        this.detailSelectedCity = cityName;

                        // 切换到分类详情视图
                        this.currentView = 'category';

                        // 等待视图切换完成后，获取数据
                        this.$nextTick(function() {
                            self.fetchData();

                            // 监听规格列表更新，自动选中指定规格
                            var checkSpec = setInterval(function() {
                                var specIndex = self.detailSpecList.indexOf(spec);
                                if (specIndex > -1) {
                                    self.activeSpecIndex = specIndex;
                                    clearInterval(checkSpec);
                                }
                            }, 100);

                            // 如果超过3秒还没找到规格，就停止检查
                            setTimeout(function() {
                                clearInterval(checkSpec);
                            }, 3000);
                        });
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
                                start_date: this.startDate,
                                end_date: this.endDate
                            }
                        }, function(data, ret) {
                            self.loading = false;
                            self.categoryName = data.category_name;

                            // 存储按城市组织的数据
                            self.chartDataByCity = data.chart_data_by_city || {};
                            self.detailCityList = data.city_list || [];

                            // 如果已经设置了城市且在列表中，则保留；否则选中第一个城市
                            if (self.detailCityList.length > 0) {
                                if (self.detailSelectedCity && self.detailCityList.indexOf(self.detailSelectedCity) > -1) {
                                    // 保留已设置的城市
                                    self.updateSpecListForCity();
                                } else {
                                    // 选中第一个城市
                                    self.detailSelectedCity = self.detailCityList[0];
                                    self.updateSpecListForCity();
                                }
                            } else {
                                self.detailSelectedCity = '';
                                self.detailSpecList = [];
                                self.currentStats = null;
                            }

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

                    // 详情视图城市变化时更新规格列表
                    onDetailCityChange: function() {
                        this.updateSpecListForCity();
                        this.renderChart();
                    },

                    // 更新当前城市的规格列表
                    updateSpecListForCity: function() {
                        if (!this.detailSelectedCity || !this.chartDataByCity[this.detailSelectedCity]) {
                            this.detailSpecList = [];
                            this.activeSpecIndex = 0;
                            this.currentStats = null;
                            this.currentShopList = [];
                            this.currentMerchants = [];
                            this.selectedShops = [];
                            return;
                        }

                        var cityData = this.chartDataByCity[this.detailSelectedCity];
                        this.detailSpecList = Object.keys(cityData);
                        this.activeSpecIndex = 0;
                        this.updateCurrentStats();
                    },

                    // 更新当前统计数据
                    updateCurrentStats: function() {
                        if (!this.detailSelectedCity || !this.chartDataByCity[this.detailSelectedCity]) {
                            this.currentStats = null;
                            this.currentShopList = [];
                            this.currentMerchants = [];
                            this.selectedShops = [];
                            return;
                        }

                        var cityData = this.chartDataByCity[this.detailSelectedCity];
                        var spec = this.detailSpecList[this.activeSpecIndex];
                        if (spec && cityData[spec]) {
                            this.currentStats = cityData[spec].current_stats;
                            this.currentShopList = cityData[spec].shop_list || [];
                            this.currentMerchants = cityData[spec].merchants || [];
                            // 默认选中所有店铺
                            this.selectedShops = this.currentShopList.map(function(shop) {
                                return shop.shop_id;
                            });
                        } else {
                            this.currentStats = null;
                            this.currentShopList = [];
                            this.currentMerchants = [];
                            this.selectedShops = [];
                        }
                    },

                    // 切换店铺选择
                    toggleShopSelection: function(shopId) {
                        var index = this.selectedShops.indexOf(shopId);
                        if (index > -1) {
                            this.selectedShops.splice(index, 1);
                        } else {
                            this.selectedShops.push(shopId);
                        }
                    },

                    // 全选店铺
                    selectAllShops: function() {
                        this.selectedShops = this.currentShopList.map(function(shop) {
                            return shop.shop_id;
                        });
                    },

                    // 取消全选店铺
                    deselectAllShops: function() {
                        this.selectedShops = [];
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
                        if (!this.chart) {
                            return;
                        }

                        // 从新的数据结构获取数据
                        if (!this.detailSelectedCity || !this.chartDataByCity[this.detailSelectedCity]) {
                            this.chart.clear();
                            return;
                        }

                        var cityData = this.chartDataByCity[this.detailSelectedCity];
                        var spec = this.detailSpecList[this.activeSpecIndex];
                        if (!spec || !cityData[spec]) {
                            this.chart.clear();
                            return;
                        }

                        var self = this;
                        var data = cityData[spec];
                        var dates = data.dates;
                        var platformPrices = data.platform ? data.platform.prices : [];
                        var merchantAvgPrices = data.merchant_avg_prices;
                        var merchants = data.merchants || [];

                        // 商家颜色列表
                        var merchantColors = [
                            '#e74c3c', '#9b59b6', '#1abc9c', '#e67e22', '#2ecc71',
                            '#3498db', '#f1c40f', '#95a5a6', '#d35400', '#27ae60'
                        ];

                        // 构建系列数据
                        var series = [];
                        var legendData = [];

                        // 1. 平台价格线（始终显示）
                        if (data.platform && data.platform.goods_title) {
                            legendData.push('平台: ' + data.platform.goods_title);
                            series.push({
                                name: '平台: ' + data.platform.goods_title,
                                type: 'line',
                                smooth: false,
                                step: 'end',
                                symbol: 'circle',
                                symbolSize: 5,
                                lineStyle: {
                                    color: '#3c8dbc',
                                    width: 3
                                },
                                itemStyle: {
                                    color: '#3c8dbc',
                                    borderColor: '#fff',
                                    borderWidth: 2
                                },
                                data: platformPrices,
                                connectNulls: true,
                                markPoint: {
                                    data: [
                                        { type: 'max', name: '最高价' },
                                        { type: 'min', name: '最低价' }
                                    ],
                                    label: {
                                        formatter: '￥{c}'
                                    }
                                }
                            });
                        }

                        // 2. 商家均价线（可选）
                        if (this.showMerchantAvg && merchantAvgPrices && merchantAvgPrices.length > 0) {
                            legendData.push('商家均价');
                            series.push({
                                name: '商家均价',
                                type: 'line',
                                smooth: false,
                                step: 'end',
                                symbol: 'diamond',
                                symbolSize: 5,
                                lineStyle: {
                                    color: '#f39c12',
                                    width: 2,
                                    type: 'dashed'
                                },
                                itemStyle: {
                                    color: '#f39c12',
                                    borderColor: '#fff',
                                    borderWidth: 1
                                },
                                data: merchantAvgPrices,
                                connectNulls: true
                            });
                        }

                        // 3. 选中店铺的商家SKU价格线
                        var colorIndex = 0;
                        merchants.forEach(function(merchant) {
                            if (self.selectedShops.indexOf(merchant.shop_id) > -1) {
                                var seriesName = merchant.shop_name + ': ' + merchant.goods_title;
                                var color = merchantColors[colorIndex % merchantColors.length];
                                colorIndex++;

                                legendData.push(seriesName);
                                series.push({
                                    name: seriesName,
                                    type: 'line',
                                    smooth: false,
                                    step: 'end',
                                    symbol: 'triangle',
                                    symbolSize: 4,
                                    lineStyle: {
                                        color: color,
                                        width: 2
                                    },
                                    itemStyle: {
                                        color: color,
                                        borderColor: '#fff',
                                        borderWidth: 1
                                    },
                                    data: merchant.prices,
                                    connectNulls: true
                                });
                            }
                        });

                        var option = {
                            title: {
                                text: this.detailSelectedCity + ' - ' + spec + ' 规格价格走势',
                                left: 'center',
                                textStyle: {
                                    fontSize: 14,
                                    fontWeight: 500,
                                    color: '#333'
                                }
                            },
                            tooltip: {
                                trigger: 'axis',
                                backgroundColor: 'rgba(50, 50, 50, 0.95)',
                                borderWidth: 0,
                                textStyle: {
                                    color: '#fff',
                                    fontSize: 12
                                },
                                formatter: function(params) {
                                    var date = params[0].axisValue;
                                    var html = '<div style="font-weight: 600; margin-bottom: 8px; border-bottom: 1px solid rgba(255,255,255,0.2); padding-bottom: 5px;">' + date + '</div>';

                                    params.forEach(function(item) {
                                        if (item.value !== null && item.value !== '-') {
                                            var color = item.color;
                                            html += '<div style="display: flex; justify-content: space-between; align-items: center; margin: 3px 0; min-width: 200px;">';
                                            html += '<span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: ' + color + '; margin-right: 6px; flex-shrink: 0;"></span>';
                                            html += '<span style="flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 150px;">' + item.seriesName + '</span>';
                                            html += '<span style="font-weight: 600; margin-left: 10px;">￥' + item.value + '</span>';
                                            html += '</div>';
                                        }
                                    });

                                    return html;
                                }
                            },
                            legend: {
                                data: legendData,
                                bottom: 10,
                                textStyle: {
                                    color: '#666',
                                    fontSize: 11
                                },
                                type: 'scroll',
                                pageIconSize: 12
                            },
                            grid: {
                                left: '3%',
                                right: '4%',
                                bottom: '18%',
                                top: '12%',
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
                            series: series
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
