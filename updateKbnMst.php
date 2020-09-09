<?php
/*****************************************************************************/
/**
 * 処理ID：PRO_MST002
 * 処理名：前期研修D施設区分・会員区分更新処理
 * 使用テーブル：施設研修区分(dtb_m_facility_training_section)
 *               会員マスタ(dtb_m_member)
 *               会員勤務先情報(dtb_m_member_work)
 *               会員アカウントロール情報(dtb_m_member_account_role_info)
 * 使用ファイル：バッチ実行ログ(/var/www/ecorange/html/core/protected/runtime/jpta_batch.log)
 * 概要：施設に所属する会員の中に登録理学療法士が存在するかどうかを判定し、施設のD区分を変更する。また、会員の状態に応じて前期履修D区分を変更する。
 */
class UpdateKbnMst extends JptaBatchCommand 
{
    // 削除フラグ
    const FLG_DEL_OFF = 0; // 有効
    const FLG_DEL_ON = 1; // 削除

    // 前期研修D施設区分
    const FACILITYKBN_NONE = '00'; // どちらでもない
    const FACILITYKBN_D101 = '01'; // D-1(イ)
    const FACILITYKBN_D102 = '02'; // D-1(ロ)

    // 主な勤務先
    const FLG_KINMU_SUB = 0; // 副勤務先
    const FLG_KINMU_MAIN = 1; // 主勤務先

    // 前期研修D会員区分
    const MEMFACILITYKBN_D101 = '01'; // D-1(イ)
    const MEMFACILITYKBN_D102 = '02'; // D-1(ロ)
    const MEMFACILITYKBN_NONE = '03'; // D-1(ハ)またはD-2(ニ)

    // @ToDo 暫定対応
    // ロールID
    const DMR_ID_REGISTERED_PHYSICAL_THERAPIST = 'MYP_R10'; 

    private $wk_facility_tbl = [];
    private $wk_member_tbl = [];

    public function __construct($name, $runner)
    {
        parent::__construct($name, $runner);
        $this->start('前期研修D施設区分・会員区分更新処理');
        $this->execute();
        $this->end('前期研修D施設区分・会員区分更新処理');
    }

    public function execute()
    {
        // システムマスタのシステム日付を取得する。
        $system = System::model()->find();
        
        try {
            // 取得したシステム日付を１日減算する。
            $ymdSystem =  date('Y-m-d', strtotime('-1 day', strtotime($system->ymd_system)));
        } catch (Exception $exception) {
            $this->error($exception->getMessage());
            $this->error($exception->getTraceAsString());
            $this->error($system);
        }

        // 前期研修D施設区分・会員区分状態遷移処理
        $this->facilityLinkKaiinUpd($ymdSystem);

        // 会員区分状態遷移処理（施設IDに紐づいていない会員が対象）
        $this->facilityNoLinkKaiin();
        try {
            // トランザクション開始
            $transaction = Yii::app()->db->beginTransaction();

            // 施設区分更新
            try {
                foreach ( $this->wk_facility_tbl as $id_facility => $kbn_d_first ) {
                    $facility = FacilityTrainingSection::model()->findByPk($id_facility);
                    $facility->kbn_d_first = $kbn_d_first;
                    if (!$facility->save()) {
                        throw new Exception('施設区分更新に失敗しました');
                    }
                }
            } catch (Exception $exception) {
                $this->error($exception->getMessage());
                $this->error($exception->getTraceAsString());
            }

            // 会員区分更新
            try {
                foreach ( $this->wk_member_tbl as $no_kaiin => $kbn_d_first ) {
                    $member = MMember::model()->findByPk($no_kaiin);
                    $member->kbn_d_first = $kbn_d_first;
                    if (!$member->save()) {
                        throw new Exception('会員マスタ更新に失敗しました');
                    }
                }
            } catch (Exception $exception) {
                $this->error($exception->getMessage());
                $this->error($exception->getTraceAsString());
            }

            // トランザクション処理終了
            $transaction->commit();
        } catch (Exception $exception) {
            $transaction->rollback();
            throw $exception;
        }

        unset($transaction);
        unset($member);
        unset($facility);
        unset($ymdSystem);
        unset($system);
    }

    /**
     * 前期研修D施設区分・会員区分状態遷移処理
     * 施設に所属する会員の中に登録理学療法士が存在するかどうかを判定し、施設のD区分を変更する。また、会員の状態に応じて前期履修D区分を変更する。
     */
    private function facilityLinkKaiinUpd($ymdSystem)
    {
        $ymdSystem = '2020-08-15';

        $criteria = new CDbCriteria;
        $criteria->condition=('flg_del = :flg AND kbn_d_first_update = :date');
        $criteria->params=array('flg'=>self::FLG_DEL_OFF, 'date'=>$ymdSystem);
        $facilityDataRecords = FacilityTrainingSection::model()->findAll($criteria);

        foreach ($facilityDataRecords as $facilityDataRecord) {
            $criteria = new CDbCriteria();
            $criteria->join='INNER JOIN dtb_m_member_account_role_info roleinfo ON `roleinfo`.id_account = `t`.id_account';
            $criteria->condition = "`t`.flg_del = :flg AND `t`.id_facility = :id_facility AND `t`.flg_main = :flg_main AND `roleinfo`.flg_del = :flg AND `roleinfo`.dmr_id = :dmr_id";
            $criteria->params=array('flg'=>self::FLG_DEL_OFF, 'id_facility'=>$facilityDataRecord['id_facility'], 'flg_main'=>self::FLG_KINMU_MAIN, 'dmr_id'=>self::DMR_ID_REGISTERED_PHYSICAL_THERAPIST);
            $memberRecords = MemberWork::model()->findAll($criteria);

            // 期研修D施設区分と上記で取得したデータから登録PT有無を判断して変更後区分を決定
            if (in_array($facilityDataRecord['kbn_d_first'], [self::FACILITYKBN_D101, self::FACILITYKBN_D102])) {
                // データが取得できなかった場合（登録PTがいない）
                if (empty($memberRecords)) {
                    $this->wk_facility_tbl += array($facilityDataRecord['id_facility']=>self::FACILITYKBN_NONE);
                }
            } else {
                // データが取得できた場合（登録PTがいる）
                if (!empty($memberRecords)) {
                    $this->wk_facility_tbl += array($facilityDataRecord['id_facility']=>self::FACILITYKBN_D101);
                }
            }

            // 施設IDに紐づく会員情報を取得
//            $criteria = new CDbCriteria();
//            $criteria->join='INNER JOIN dtb_m_member member ON `t`.no_kaiin = member.no_kaiin';
//            $criteria->condition = "`t`.flg_del = :flg AND `t`.id_facility = :id_facility AND `t`.flg_main = :flg_main AND `member`.flg_del = :flg";
//            $criteria->params=array('flg'=>self::FLG_DEL_OFF, 'id_facility'=>$facilityDataRecord['id_facility'], 'flg_main'=>self::FLG_KINMU_MAIN);
//            $kaiinRecords = MemberWork::model()->findAll($criteria);
            $criteria = new CDbCriteria();
            $criteria->join='INNER JOIN dtb_m_member_work work ON `t`.no_kaiin = `work`.no_kaiin';
            
            
            
            $criteria->condition = "`t`.flg_del = :flg AND `work`.id_facility = :id_facility AND `work`.flg_main = :flg_main AND `work`.flg_del = :flg";
            $criteria->params=array('flg'=>self::FLG_DEL_OFF, 'id_facility'=>$facilityDataRecord['id_facility'], 'flg_main'=>self::FLG_KINMU_MAIN);
            //$criteria->condition = "`t`.flg_del = :flg AND `t`.id_facility = :id_facility AND `work`.flg_main = :flg_main AND `work`.flg_del = :flg";
            //$criteria->params=array('flg'=>self::FLG_DEL_OFF, 'id_facility'=>$facilityDataRecord['id_facility'], 'flg_main'=>self::FLG_KINMU_MAIN);
            
            
            
            
            $kaiinRecords = MMember::model()->findAll($criteria);
            foreach ($kaiinRecords as $kaiinRecord) {
            echo $kaiinRecord['no_kaiin'];
            echo $kaiinRecord['id_todoufukenshikai'];
            echo $kaiinRecord['kbn_syugyoukeitai'];
                // 所属士会ID：海外 or 国内（就労・就学していない）
                if (in_array($kaiinRecord['id_todoufukenshikai'], [PrefectureAssociation::ID_FOREIGN]) ||
                    in_array($kaiinRecord['kbn_syugyoukeitai'], [KbnSyugyoukeitai::NONE])) {
                    $this->wk_member_tbl += array($kaiinRecord['no_kaiin']=>self::MEMFACILITYKBN_NONE);
                // 国内（就労・就学している）
                } else {
                    $new_kbn_d_first = in_array($kbn_d_first, [self::FACILITYKBN_NONE], true) ? self::MEMFACILITYKBN_NONE : $kbn_d_first;
                    $this->wk_member_tbl += array($kaiinRecord['no_kaiin']=>$new_kbn_d_first);
                }
            }
        }
        unset($criteria);
        unset($DataRecords);
        unset($kaiinRecords);
        unset($kaiinRecord);
        unset($new_kbn_d_first);
        unset($id_facility);
        unset($kbn_d_first);
    }

    /**
     * 会員区分状態遷移処理（施設IDに紐づいていない会員が対象）
     * 
     */
    private function facilityNoLinkKaiin()
    {
        // 会員マスタ反映可能データ取得
        $criteria = new CDbCriteria;
        $criteria->condition=('flg_del = :flg AND kbn_d_first IN (:kbn1, :kbn2) AND (( id_todoufukenshikai = :todoufukenshikai ) OR ( id_todoufukenshikai != :todoufukenshikai AND kbn_syugyoukeitai = :syugyoukeitai ))');
        $criteria->params=array('flg'=>self::FLG_DEL_OFF, 'kbn1'=>self::MEMFACILITYKBN_D101, 'kbn2'=>self::MEMFACILITYKBN_NONE, 'todoufukenshikai'=>PrefectureAssociation::ID_FOREIGN, 'syugyoukeitai'=>KbnSyugyoukeitai::NONE);
        $kaiinRecords = MMember::model()->findAll($criteria);

        // 海外会員もしくは働いていない会員の情報を取得
        foreach ($kaiinRecords as $kaiinRecord) {
            // 施設IDに紐づいた会員かどうかの判断を行う
            if (!array_key_exists($kaiinRecord['no_kaiin'],  $this->wk_member_tbl)) {
                $this->wk_member_tbl += array($kaiinRecord['no_kaiin']=>self::MEMFACILITYKBN_NONE);
            }
        }
        unset($criteria);
        unset($kaiinRecord);
        unset($kaiinRecords);
    }
}