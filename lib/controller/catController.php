<?php

include_once INIT::$MODEL_ROOT . "/queries.php";
//include_once INIT::$UTILS_ROOT . "/filetype.class.php";
include_once INIT::$UTILS_ROOT . "/CatUtils.php";
include_once INIT::$UTILS_ROOT . "/langs/languages.class.php";
include_once INIT::$UTILS_ROOT . '/QA.php';

/**
 * Description of catController
 *
 * @author antonio
 */
class catController extends viewController {

    private $data = array();
    private $cid = "";
    private $jid = "";
    private $tid = "";
    private $password = "";
    private $source = "";
    private $pname = "";
    private $create_date = "";
    private $project_status = 'NEW';
    private $start_from = 0;
    private $page = 0;
    private $start_time = 0.00;
    private $downloadFileName;
    private $job_stats = array();
    private $source_rtl = false;
    private $target_rtl = false;
    private $job_owner = "";

    private $job_not_found = false;
    private $job_archived = false;
    private $job_cancelled = false;

    private $firstSegmentOfFiles = '[]';
    private $fileCounter = '[]';

    private $first_job_segment;
    private $last_job_segment;
    private $last_opened_segment;

    private $qa_data = '[]';
    private $qa_overall = '';

    private $_keyList = array( 'totals' => array(), 'job_keys' => array() );

    /**
     * @var string
     */
    private $thisUrl;
    private $translation_engines;

    private $mt_id;

    public function __construct() {
        $this->start_time = microtime( 1 ) * 1000;

        parent::__construct( false );
        parent::makeTemplate( "index.html" );

        $filterArgs = array(
                'jid'      => array( 'filter' => FILTER_SANITIZE_NUMBER_INT ),
                'password' => array(
                        'filter' => FILTER_SANITIZE_STRING, 'flags' => FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH
                ),
                'start'    => array( 'filter' => FILTER_SANITIZE_NUMBER_INT ),
                'page'     => array( 'filter' => FILTER_SANITIZE_NUMBER_INT )
        );
        $getInput   = (object)filter_input_array( INPUT_GET, $filterArgs );

        $this->jid        = $getInput->jid;
        $this->password   = $getInput->password;
        $this->start_from = $getInput->start;
        $this->page       = $getInput->page;

        if ( isset( $_GET[ 'step' ] ) ) {
            $this->step = $_GET[ 'step' ];
        } else {
            $this->step = 1000;
        };

        if ( is_null( $this->page ) ) {
            $this->page = 1;
        }
        if ( is_null( $this->start_from ) ) {
            $this->start_from = ( $this->page - 1 ) * $this->step;
        }

        if ( isset( $_GET[ 'filter' ] ) ) {
            $this->filter_enabled = true;
        } else {
            $this->filter_enabled = false;
        };

        $this->downloadFileName = "";

        $this->doAuth();

        $this->generateAuthURL();

    }

    private function doAuth() {

        //if no login set and login is required
        if ( !$this->isLoggedIn() ) {
            //take note of url we wanted to go after
            $this->thisUrl = $_SESSION[ 'incomingUrl' ] = $_SERVER[ 'REQUEST_URI' ];
        }

    }

    public function doAction() {
        $files_found  = array();
        $lang_handler = Languages::getInstance();

        $data = getSegmentsInfo( $this->jid, $this->password );
        if ( empty( $data ) or $data < 0 ) {
            $this->job_not_found = true;

            //stop execution
            return;
        }

        //retrieve job owner. It will be useful also if the job is archived or cancelled
        $this->job_owner = ( $data[ 0 ][ 'job_owner' ] != "" ) ? $data[ 0 ][ 'job_owner' ] : "support@matecat.com";

        if ( $data[ 0 ][ 'status' ] == Constants_JobStatus::STATUS_CANCELLED ) {
            $this->job_cancelled = true;

            //stop execution
            return;
        }

        if ( $data[ 0 ][ 'status' ] == Constants_JobStatus::STATUS_ARCHIVED ) {
            $this->job_archived = true;
//			$this->setTemplateVars();
            //stop execution
            return;
        }

        /*
         * I prefer to use a programmatic approach to the check for the archive date instead of a pure query
         * because the query to check "Utils::getArchivableJobs($this->jid)" should be
         * executed every time a job is loaded ( F5 or CTRL+R on browser ) and it cost some milliseconds ( ~0.1s )
         * and it is little heavy for the database.
         * We use the data we already have from last query and perform
         * the check on the last translation only if the job is older than 30 days
         *
         */
        $lastUpdate  = new DateTime( $data[ 0 ][ 'last_update' ] );
        $oneMonthAgo = new DateTime();
        $oneMonthAgo->modify( '-' . INIT::JOB_ARCHIVABILITY_THRESHOLD . ' days' );

        if ( $lastUpdate < $oneMonthAgo && !$this->job_cancelled ) {

            $lastTranslationInJob = new Datetime( getLastTranslationDate( $this->jid ) );

            if ( $lastTranslationInJob < $oneMonthAgo ) {
                $res        = "job";
                $new_status = Constants_JobStatus::STATUS_ARCHIVED;
                updateJobsStatus( $res, $this->jid, $new_status, null, null, $this->password );
                $this->job_archived = true;
            }

        }

        foreach ( $data as $i => $job ) {

            $this->project_status = $job; // get one row values for the project are the same for every row

            if ( empty( $this->pname ) ) {
                $this->pname            = $job[ 'pname' ];
                $this->downloadFileName = $job[ 'pname' ] . ".zip"; // will be overwritten below in case of one file job
            }

            if ( empty( $this->last_opened_segment ) ) {
                $this->last_opened_segment = $job[ 'last_opened_segment' ];
            }

            if ( empty( $this->cid ) ) {
                $this->cid = $job[ 'cid' ];
            }

            if ( empty( $this->pid ) ) {
                $this->pid = $job[ 'pid' ];
            }

            if ( empty( $this->create_date ) ) {
                $this->create_date = $job[ 'create_date' ];
            }

            if ( empty( $this->source_code ) ) {
                $this->source_code = $job[ 'source' ];
            }

            if ( empty( $this->target_code ) ) {
                $this->target_code = $job[ 'target' ];
            }

            if ( empty( $this->source ) ) {
                $s                = explode( "-", $job[ 'source' ] );
                $source           = strtoupper( $s[ 0 ] );
                $this->source     = $source;
                $this->source_rtl = ( $lang_handler->isRTL( strtolower( $this->source ) ) ) ? ' rtl-source' : '';
            }

            if ( empty( $this->target ) ) {
                $t                = explode( "-", $job[ 'target' ] );
                $target           = strtoupper( $t[ 0 ] );
                $this->target     = $target;
                $this->target_rtl = ( $lang_handler->isRTL( strtolower( $this->target ) ) ) ? ' rtl-target' : '';
            }
            //check if language belongs to supported right-to-left languages


            if ( $job[ 'status' ] == Constants_JobStatus::STATUS_ARCHIVED ) {
                $this->job_archived = true;
                $this->job_owner    = $data[ 0 ][ 'job_owner' ];
            }

            $id_file = $job[ 'id_file' ];

            if ( !isset( $this->data[ "$id_file" ] ) ) {
                $files_found[ ] = $job[ 'filename' ];
            }

            $wStruct = new WordCount_Struct();

            $wStruct->setIdJob( $this->jid );
            $wStruct->setJobPassword( $this->password );
            $wStruct->setNewWords( $job[ 'new_words' ] );
            $wStruct->setDraftWords( $job[ 'draft_words' ] );
            $wStruct->setTranslatedWords( $job[ 'translated_words' ] );
            $wStruct->setApprovedWords( $job[ 'approved_words' ] );
            $wStruct->setRejectedWords( $job[ 'rejected_words' ] );

            unset( $job[ 'id_file' ] );
            unset( $job[ 'source' ] );
            unset( $job[ 'target' ] );
            unset( $job[ 'source_code' ] );
            unset( $job[ 'target_code' ] );
            unset( $job[ 'mime_type' ] );
            unset( $job[ 'filename' ] );
            unset( $job[ 'jid' ] );
            unset( $job[ 'pid' ] );
            unset( $job[ 'cid' ] );
            unset( $job[ 'tid' ] );
            unset( $job[ 'pname' ] );
            unset( $job[ 'create_date' ] );
            unset( $job[ 'owner' ] );

            unset( $job[ 'last_opened_segment' ] );

            unset( $job[ 'new_words' ] );
            unset( $job[ 'draft_words' ] );
            unset( $job[ 'translated_words' ] );
            unset( $job[ 'approved_words' ] );
            unset( $job[ 'rejected_words' ] );

            //For projects created with No tm analysis enabled
            if ( $wStruct->getTotal() == 0 && ( $job[ 'status_analysis' ] == Constants_ProjectStatus::STATUS_DONE || $job[ 'status_analysis' ] == Constants_ProjectStatus::STATUS_NOT_TO_ANALYZE ) ) {
                $wCounter = new WordCount_Counter();
                $wStruct  = $wCounter->initializeJobWordCount( $this->jid, $this->password );
                Log::doLog( "BackWard compatibility set Counter." );
            }

            $this->job_stats = CatUtils::getFastStatsForJob( $wStruct );

        }

        //Needed because a just created job has last_opened segment NULL
        if ( empty( $this->last_opened_segment ) ) {
            $this->last_opened_segment = getFirstSegmentId( $this->jid, $this->password );
        }

        $this->first_job_segment = $this->project_status[ 'job_first_segment' ];
        $this->last_job_segment  = $this->project_status[ 'job_last_segment' ];

        if ( count( $files_found ) == 1 ) {
            $this->downloadFileName = $files_found[ 0 ];
        }

        /**
         * get first segment of every file
         */
        $fileInfo     = getFirstSegmentOfFilesInJob( $this->jid );
        $TotalPayable = array();
        foreach ( $fileInfo as $file ) {
            $TotalPayable[ $file[ 'id_file' ] ][ 'TOTAL_FORMATTED' ] = $file[ 'TOTAL_FORMATTED' ];
        }
        $this->firstSegmentOfFiles = json_encode( $fileInfo );
        $this->fileCounter         = json_encode( $TotalPayable );


        list( $uid, $user_email ) = $this->getLoginUserParams();

        if ( self::isRevision() ) {
            $this->userRole   = TmKeyManagement_Filter::ROLE_REVISOR;
        } elseif ( $user_email == $data[ 0 ][ 'job_owner' ] ) {
            $this->userRole = TmKeyManagement_Filter::OWNER;
        } else {
            $this->userRole = TmKeyManagement_Filter::ROLE_TRANSLATOR;
        }

        /*
         * Take the keys of the user
         */
        try {
            $_keyList = new TmKeyManagement_MemoryKeyDao( Database::obtain() );
            $dh       = new TmKeyManagement_MemoryKeyStruct( array( 'uid' => $uid ) );

            $keyList = $_keyList->read( $dh );

        } catch ( Exception $e ) {
            $keyList = array();
            Log::doLog( $e->getMessage() );
        }

        $reverse_lookup_user_personal_keys = array( 'pos' => array(), 'elements' => array() );
        /**
         * Set these keys as editable for the client
         *
         * @var $keyList TmKeyManagement_MemoryKeyStruct[]
         */
        foreach ( $keyList as $_j => $key ) {

            /**
             * @var $_client_tm_key TmKeyManagement_TmKeyStruct
             */

            //create a reverse lookup
            $reverse_lookup_user_personal_keys[ 'pos' ][ $_j ]      = $key->tm_key->key;
            $reverse_lookup_user_personal_keys[ 'elements' ][ $_j ] = $key;

            $this->_keyList[ 'totals' ][ $_j ] = new TmKeyManagement_ClientTmKeyStruct( $key->tm_key );

        }

        /*
         * Now take the JOB keys
         */
        $job_keyList = json_decode( $data[ 0 ][ 'tm_keys' ], true );

        $this->tid = count( $job_keyList ) > 0;

        /**
         * Start this N^2 cycle from keys of the job,
         * these should be statistically lesser than the keys of the user
         *
         * @var $keyList array
         */
        foreach ( $job_keyList as $jobKey ) {

            $jobKey = new TmKeyManagement_ClientTmKeyStruct( $jobKey );

            if ( $this->isLoggedIn() && count( $reverse_lookup_user_personal_keys[ 'pos' ] ) ) {

                /*
                 * If user has some personal keys, check for the job keys if they are present, and obfuscate
                 * when they are not
                 */
                $_index_position = array_search( $jobKey->key, $reverse_lookup_user_personal_keys[ 'pos' ] );
                if ( $_index_position !== false ) {

                    //i found a key in the job that is present in my database
                    //i'm owner?? and the key is an owner type key?
                    if ( !$jobKey->owner && $this->userRole != TmKeyManagement_Filter::OWNER ) {
                        $jobKey->r = $jobKey->{TmKeyManagement_Filter::$GRANTS_MAP[ $this->userRole ][ 'r' ]};
                        $jobKey->w = $jobKey->{TmKeyManagement_Filter::$GRANTS_MAP[ $this->userRole ][ 'w' ]};
                        $jobKey    = $jobKey->hideKey( $uid );
                    } else {
                        if ( $jobKey->owner && $this->userRole != TmKeyManagement_Filter::OWNER ) {
                            // I'm not the job owner, but i know the key because it is in my keyring
                            // so, i can upload and download TMX, but i don't want it to be removed from job
                            // in tm.html relaxed the control to "key.edit" to enable buttons
//                            $jobKey = $jobKey->hideKey( $uid ); // enable editing

                        } else {
                            if ( $jobKey->owner && $this->userRole == TmKeyManagement_Filter::OWNER ) {
                                //do Nothing
                            }
                        }
                    }

                    unset( $this->_keyList[ 'totals' ][ $_index_position ] );

                } else {

                    /*
                     * This is not a key of that user, set right and obfuscate
                     */
                    $jobKey->r = true;
                    $jobKey->w = true;
                    $jobKey    = $jobKey->hideKey( -1 );

                }

                $this->_keyList[ 'job_keys' ][ ] = $jobKey;

            } else {
                /*
                 * This user is anonymous or it has no keys in its keyring, obfuscate all
                 */
                $jobKey->r                       = true;
                $jobKey->w                       = true;
                $this->_keyList[ 'job_keys' ][ ] = $jobKey->hideKey( -1 );

            }

        }

        //clean unordered keys
        $this->_keyList[ 'totals' ] = array_values( $this->_keyList[ 'totals' ] );


        /**
         * Retrieve information about job errors
         * ( Note: these information are fed by the revision process )
         * @see setRevisionController
         */

        $jobQA = new Revise_JobQA(
                $this->jid,
                $this->password,
                $wStruct->getTotal()
        );

        $jobQA->retrieveJobErrorTotals();
        $jobVote = $jobQA->evalJobVote();
        $this->qa_data    = json_encode( $jobQA->getQaData() );
        $this->qa_overall = $jobVote[ 'minText' ];


        $engine = new EnginesModel_EngineDAO( Database::obtain() );

        //this gets all engines of the user
        if( $this->isLoggedIn() ){
            $engineQuery         = new EnginesModel_EngineStruct();
            $engineQuery->type   = 'MT';
            $engineQuery->uid    = $uid;
            $engineQuery->active = 1;
            $mt_engines          = $engine->read( $engineQuery );
        } else $mt_engines = array();

        // this gets MyMemory
        $engineQuery                      = new EnginesModel_EngineStruct();
        $engineQuery->type                = 'TM';
        $engineQuery->active              = 1;
        $tms_engine                       = $engine->setCacheTTL( 3600 * 24 * 30 )->read( $engineQuery );

        //this gets MT engine active for the job
        $engineQuery                            = new EnginesModel_EngineStruct();
        $engineQuery->id                        = $this->project_status[ 'id_mt_engine' ];
        $engineQuery->active                    = 1;
        $active_mt_engine                       = $engine->setCacheTTL( 60 * 10 )->read( $engineQuery );

        /*
         * array_unique cast EnginesModel_EngineStruct to string
         *
         * EnginesModel_EngineStruct implements __toString method
         *
         */
        $this->translation_engines = array_unique( array_merge( $active_mt_engine, $tms_engine, $mt_engines ) );

    }

    public function setTemplateVars() {

        if ( $this->job_not_found || $this->job_cancelled ) {
            $this->template->pid                 = null;
            $this->template->target              = null;
            $this->template->source_code         = null;
            $this->template->target_code         = null;
            $this->template->firstSegmentOfFiles = 0;
            $this->template->fileCounter         = 0;
        } else {
            $this->template->pid                 = $this->pid;
            $this->template->target              = $this->target;
            $this->template->source_code         = $this->source_code;
            $this->template->target_code         = $this->target_code;
            $this->template->firstSegmentOfFiles = $this->firstSegmentOfFiles;
            $this->template->fileCounter         = $this->fileCounter;
        }
        $this->template->page        = 'cattool';
        $this->template->jid         = $this->jid;
        $this->template->password    = $this->password;
        $this->template->cid         = $this->cid;
        $this->template->create_date = $this->create_date;
        $this->template->pname       = $this->pname;
        $this->template->tid         = var_export( $this->tid, true );
        $this->template->source      = $this->source;
        $this->template->source_rtl  = $this->source_rtl;
        $this->template->target_rtl  = $this->target_rtl;

        $this->template->authURL = $this->authURL;

        $this->template->mt_engines         = $this->translation_engines;
        $this->template->mt_id              = $this->project_status[ 'id_mt_engine' ];

        $this->template->first_job_segment   = $this->first_job_segment;
        $this->template->last_job_segment    = $this->last_job_segment;
        $this->template->last_opened_segment = $this->last_opened_segment;
        $this->template->owner_email         = $this->job_owner;
        $this->template->ownerIsMe           = ( $this->logged_user[ 'email' ] == $this->job_owner );

        $this->template->isLogged            = $this->isLoggedIn(); // used in template
        $this->template->isAnonymousUser     = var_export( !$this->isLoggedIn() , true );  // used by the client

        $this->job_stats[ 'STATUS_BAR_NO_DISPLAY' ] = ( $this->project_status[ 'status_analysis' ] == Constants_ProjectStatus::STATUS_DONE ? '' : 'display:none;' );
        $this->job_stats[ 'ANALYSIS_COMPLETE' ]     = ( $this->project_status[ 'status_analysis' ] == Constants_ProjectStatus::STATUS_DONE ? true : false );

        $this->template->user_keys             = $this->_keyList;
        $this->template->job_stats             = $this->job_stats;
        $this->template->stat_quality          = $this->qa_data;
        $this->template->overall_quality       = $this->qa_overall;
        $this->template->overall_quality_class = strtolower( str_replace( ' ', '', $this->qa_overall ) );

        $end_time                               = microtime( true ) * 1000;
        $load_time                              = $end_time - $this->start_time;
        $this->template->load_time              = $load_time;
        $this->template->tms_enabled            = var_export( (bool)$this->project_status[ 'id_tms' ], true );
        $this->template->mt_enabled             = var_export( (bool)$this->project_status[ 'id_mt_engine' ], true );
        $this->template->time_to_edit_enabled   = INIT::$TIME_TO_EDIT_ENABLED;
        $this->template->build_number           = INIT::$BUILD_NUMBER;
        $this->template->downloadFileName       = $this->downloadFileName;
        $this->template->job_not_found          = $this->job_not_found;
        $this->template->job_archived           = ( $this->job_archived ) ? INIT::JOB_ARCHIVABILITY_THRESHOLD : '';
        $this->template->job_cancelled          = $this->job_cancelled;
        $this->template->logged_user            = $this->logged_user[ 'short' ];
        $this->template->extended_user          = trim( $this->logged_user[ 'first_name' ] . " " . $this->logged_user[ 'last_name' ] );
        $this->template->incomingUrl            = '/login?incomingUrl=' . $this->thisUrl;
        $this->template->warningPollingInterval = 1000 * ( INIT::$WARNING_POLLING_INTERVAL );
        $this->template->segmentQACheckInterval = 1000 * ( INIT::$SEGMENT_QA_CHECK_INTERVAL );
        $this->template->filtered               = $this->filter_enabled;
        $this->template->filtered_class         = ( $this->filter_enabled ) ? ' open' : '';

        $this->template->maxFileSize    = INIT::$MAX_UPLOAD_FILE_SIZE;
        $this->template->maxTMXFileSize = INIT::$MAX_UPLOAD_TMX_FILE_SIZE;

        $this->template->isReview    = var_export( self::isRevision(), true );
        $this->template->reviewClass = ( self::isRevision() ? ' review' : '' );

        ( INIT::$VOLUME_ANALYSIS_ENABLED ? $this->template->analysis_enabled = true : null );

        //check if it is a composite language, for cjk check that accepts only ISO 639 code
        if ( strpos( $this->target_code, '-' ) !== false ) {
            //pick only first part
            $tmp_lang               = explode( '-', $this->target_code );
            $target_code_no_country = $tmp_lang[ 0 ];
            unset( $tmp_lang );
        } else {
            //not a RFC code, it's fine
            $target_code_no_country = $this->target_code;
        }

        //check if cjk
        if ( array_key_exists( $target_code_no_country, CatUtils::$cjk ) ) {
//            $this->template->taglockEnabled = 0;
        }

        /*
         * Line Feed PlaceHolding System
         */
        $this->template->brPlaceholdEnabled = $placeHoldingEnabled = true;

        if ( $placeHoldingEnabled ) {

            $this->template->lfPlaceholder        = CatUtils::lfPlaceholder;
            $this->template->crPlaceholder        = CatUtils::crPlaceholder;
            $this->template->crlfPlaceholder      = CatUtils::crlfPlaceholder;
            $this->template->lfPlaceholderClass   = CatUtils::lfPlaceholderClass;
            $this->template->crPlaceholderClass   = CatUtils::crPlaceholderClass;
            $this->template->crlfPlaceholderClass = CatUtils::crlfPlaceholderClass;
            $this->template->lfPlaceholderRegex   = CatUtils::lfPlaceholderRegex;
            $this->template->crPlaceholderRegex   = CatUtils::crPlaceholderRegex;
            $this->template->crlfPlaceholderRegex = CatUtils::crlfPlaceholderRegex;

            $this->template->tabPlaceholder      = CatUtils::tabPlaceholder;
            $this->template->tabPlaceholderClass = CatUtils::tabPlaceholderClass;
            $this->template->tabPlaceholderRegex = CatUtils::tabPlaceholderRegex;

            $this->template->nbspPlaceholder      = CatUtils::nbspPlaceholder;
            $this->template->nbspPlaceholderClass = CatUtils::nbspPlaceholderClass;
            $this->template->nbspPlaceholderRegex = CatUtils::nbspPlaceholderRegex;

        }

    }

}
