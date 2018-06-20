<?php

namespace app\swagger;

/**
 * @SWG\Definition(
 *      definition="Error",
 *      required={"code", "message"},
 *      @SWG\Property(
 *          property="name",
 *          type="string",
 *          example="Unauthorized"
 *      ),
 *      @SWG\Property(
 *          property="message",
 *          type="string",
 *          example="未授权"
 *      ),
 *      @SWG\Property(
 *          property="code",
 *          type="integer",
 *          format="int32",
 *          example=0
 *      ),
 *      @SWG\Property(
 *          property="status",
 *          type="integer",
 *          format="int32",
 *          example=401
 *      ),
 *      @SWG\Property(
 *          property="type",
 *          type="string",
 *          example="yii\\web\\UnauthorizedHttpException"
 *      )
 * )
 */

/**
 * @SWG\Definition(
 *     definition="Patent",
 *     @SWG\Property(
 *          property="application_no",
 *          type="string",
 *          description="申请号/专利号",
 *          example="2015110274572",
 *     ),
 *     @SWG\Property(
 *          property="patent_type",
 *          type="string",
 *          description="专利类型",
 *          example="发明专利",
 *     ),
 *     @SWG\Property(
 *          property="title",
 *          type="string",
 *          description="专利名称",
 *          example="顶置式多重调谐质量阻尼器减震装置",
 *     ),
 *     @SWG\Property(
 *          property="filing_date",
 *          type="string",
 *          format="date",
 *          description="申请日",
 *          example="2015-12-31",
 *     ),
 *     @SWG\Property(
 *          property="case_status",
 *          type="string",
 *          description="案件状态",
 *          example="一通回案实审",
 *     ),
 *     @SWG\Property(
 *          property="general_status",
 *          type="string",
 *          description="一般状态",
 *          example="有效",
 *     ),
 *     @SWG\Property(
 *          property="publication_no",
 *          type="string",
 *          description="公开号",
 *          example="CN105422711A",
 *     ),
 *     @SWG\Property(
 *          property="publication_date",
 *          type="string",
 *          format="date",
 *          description="公开日",
 *          example="2016-03-23",
 *     ),
 *     @SWG\Property(
 *          property="issue_no",
 *          type="string",
 *          description="授权号",
 *          example="CN105422711B",
 *     ),
 *     @SWG\Property(
 *          property="issue_announcement",
 *          type="string",
 *          description="授权日",
 *          example="2017-08-23",
 *     ),
 *     @SWG\Property(
 *          property="applicants",
 *          type="string",
 *          description="申请人",
 *          example="中国地震局工程力学研究所",
 *     ),
 *     @SWG\Property(
 *          property="inventors",
 *          type="string",
 *          description="发明人/设计人",
 *          example="杨永强、戴君武、柏文、周惠蒙、宁晓晴",
 *     ),
 *     @SWG\Property(
 *          property="ip_agency",
 *          type="string",
 *          description="代理机构名称",
 *          example="哈尔滨市阳光惠远知识产权代理有限公司",
 *     ),
 *     @SWG\Property(
 *          property="first_named_attorney",
 *          type="string",
 *          description="第一代理人",
 *          example="蔡岩岩",
 *     ),
 * )
 */

/**
 * @SWG\Definition(
 *     definition="ChangeOfBibliographic",
 *     @SWG\Property(
 *          property="date",
 *          type="string",
 *          format="date",
 *          description="变更手续处理日",
 *          example="2011-06-03",
 *     ),
 *     @SWG\Property(
 *          property="changed_item",
 *          type="string",
 *          description="变更事项",
 *          example="【主著录项变更】申请方式",
 *     ),
 *     @SWG\Property(
 *          property="before_change",
 *          type="string",
 *          description="变更前",
 *          example="纸件申请",
 *     ),
 *     @SWG\Property(
 *          property="after_change",
 *          type="string",
 *          description="变更后",
 *          example="电子申请",
 *     ),
 * )
 */

/**
 * @SWG\Definition(
 *     definition="ChangeOfBibliographicData",
 *     type="array",
 *     @SWG\Items(ref="#/definitions/ChangeOfBibliographic")
 * )
 */

/**
 * @SWG\Definition(
 *     definition="UnpaidFee",
 *     @SWG\Property(
 *          property="type",
 *          type="string",
 *          description="费用种类",
 *          example="发明专利第15年年费",
 *     ),
 *     @SWG\Property(
 *          property="amount",
 *          type="number",
 *          description="应缴金额",
 *          example=6000,
 *     ),
 *     @SWG\Property(
 *          property="due_date",
 *          type="string",
 *          format="date",
 *          description="缴费截止日",
 *          example="2018-10-14",
 *     ),
 * )
 */

/**
 * @SWG\Definition(
 *     definition="UnpaidFees",
 *     type="array",
 *     @SWG\Items(ref="#/definitions/UnpaidFee")
 * )
 */

/**
 * @SWG\Definition(
 *     definition="PaidFee",
 *     @SWG\Property(
 *          property="type",
 *          type="string",
 *          description="缴费种类",
 *          example="发明专利第8年年费",
 *     ),
 *     @SWG\Property(
 *          property="amount",
 *          type="number",
 *          description="缴费金额",
 *          example=2000,
 *     ),
 *     @SWG\Property(
 *          property="paid_date",
 *          type="string",
 *          format="date",
 *          description="缴费日期",
 *          example="2010-06-08",
 *     ),
 *     @SWG\Property(
 *          property="paid_by",
 *          type="string",
 *          description="缴费人姓名",
 *          example="哈尔滨工业大学",
 *     ),
 *     @SWG\Property(
 *          property="receipt",
 *          type="string",
 *          description="收据号",
 *          example="14669177",
 *     ),
 * )
 */

/**
 * @SWG\Definition(
 *     definition="PaidFees",
 *     type="array",
 *     @SWG\Items(ref="#/definitions/PaidFee")
 * )
 */

/**
 * @SWG\Definition(
 *     definition="OverdueFine",
 *     @SWG\Property(
 *          property="due_date",
 *          type="string",
 *          description="缴费时间",
 *          example="2017年09月22日到2017年10月23日",
 *     ),
 *     @SWG\Property(
 *          property="original_amount",
 *          type="integer",
 *          description="当前年费金额",
 *          example=6000,
 *     ),
 *     @SWG\Property(
 *          property="fine_amount",
 *          type="integer",
 *          description="应交滞纳金额",
 *          example=300,
 *     ),
 *     @SWG\Property(
 *          property="total_amount",
 *          type="integer",
 *          description="总计",
 *          example=6300,
 *     ),
 * )
 */

/**
 * @SWG\Definition(
 *     definition="OverdueFees",
 *     type="array",
 *     @SWG\Items(ref="#/definitions/OverdueFine")
 * )
 */