<?php

/**
 * 処理ID：PRO_SYS026
 * 処理名：後期実地経験加算処理
 * 使用テーブル：会員マスタ(dtb_m_member)
 * 使用ファイル：バッチ実行ログ(/var/www/ecorange/html/core/protected/runtime/jpta_batch.log)
 * 概要：新人プログラムの後期研修の実地経験を加算する。
 */
class LateHandsOnExpAdd extends JptaBatchCommand {

    //削除フラグ
    const FLG_DEL_OFF = 0; //有効
    //理学療法士区分
    const PHYSICAL_TRAININGSTUDY = '01'; //前期研修履修,後期研修履修
    //在退区分
    const LEAVING_ZAIKAI = '01'; //入会手続中
    //就業形態区分
    const SYUGYOUKEITAI_NOAFFILIATION = '03'; //就労・就学していない

    public function __construct($name, $runner)
    {
        parent::__construct($name, $runner);
        $this->start('後期実地経験加算処理');
        $this->execute();
        $this->end('後期実地経験加算処理');
    }

    public function execute() {
        echo "START";
        // 後期実地経験加算処理
        // 対象者のデータ取得
        $memberLists = Yii::app()->db->createCommand()
            ->select('*')
            ->from('dtb_m_member')
            ->where('flg_del = :flg AND kbn_pt = :pt AND kbn_zaitai = :zaitai AND (kbn_syugyoukeitai IS NOT NULL AND kbn_syugyoukeitai != :syugyoukeitai)', 
                [':flg'=>self::FLG_DEL_OFF, ':pt'=>self::PHYSICAL_TRAININGSTUDY, ':zaitai'=>self::LEAVING_ZAIKAI, ':syugyoukeitai'=>self::SYUGYOUKEITAI_NOAFFILIATION])
            ->queryAll();

        // 会員マスタ更新
        $transaction = Yii::app()->db->beginTransaction();
        try {
            foreach ($memberLists as $memberList) {
        echo "FINDX";
//echo $memberList->no_kaiin;
                $kaiinInfo = MMember::model()->findByPk(20000000);
//                $memberList->kbn_zaitai = '01'; //$kaiinInfo->work_exp_months + 1;
//                if (!$kaiinInfo->save()) {
//                    throw new Exception('会員マスタ更新に失敗しました');
//                }
            }
            $transaction->commit();
        } catch (Exception $exception) {
            $transaction->rollback();
            $this->error($exception->getTraceAsString());
        }

        unset($memberLists, $memberList, $transaction, $criteria);
      }

}