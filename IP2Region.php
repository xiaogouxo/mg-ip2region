<?php
namespace xiaogouxo\ip2region;

use Yii;
use \yii\base\Exception;

defined('INDEX_BLOCK_LENGTH')    or define('INDEX_BLOCK_LENGTH',  12);
defined('TOTAL_HEADER_LENGTH')    or define('TOTAL_HEADER_LENGTH', 4096);
class IP2Region {
    // 当前版本
    const VERSION = '1.0.0';

    const SEARCH_BTREE = 'btree';
    const SEARCH_BINARY = 'binary';
    /**
     * db file handler
     */
    private $dbFileHandler = NULL;

    /**
     * header block info
     */
    private $HeaderSip    = NULL;
    private $HeaderPtr    = NULL;
    private $headerLen  = 0;

    /**
     * super block index info
     */
    private $firstIndexPtr = 0;
    private $lastIndexPtr  = 0;
    private $totalBlocks   = 0;

    /**
     * construct method
     *
     * @param    ip2regionFile
     */
    public function __construct( $ip2regionFile )
    {
        if (!file_exists($ip2regionFile)) {
            throw new Exception('IP2Region: Unable to open file "' . $ip2regionFile . '".');
        }
        $this->dbFileHandler = fopen($ip2regionFile, 'r');
    }

    /**
     * get the data block throught the specifield ip address or long ip numeric with binary search algorithm
     *
     * @param    ip
     * @return    mixed Array or NULL for any error
     */
    public function binarySearch( $ip )
    {
        //check and conver the ip address
        if ( is_string($ip) ) $ip = ip2long($ip);
        if ( $this->totalBlocks == 0 )
        {
            fseek($this->dbFileHandler, 0);
            $superBlock = fread($this->dbFileHandler, 8);

            $this->firstIndexPtr = self::getLong($superBlock, 0);
            $this->lastIndexPtr  = self::getLong($superBlock, 4);
            $this->totalBlocks   = ($this->lastIndexPtr-$this->firstIndexPtr)/INDEX_BLOCK_LENGTH + 1;
        }

        //binary search to define the data
        $l    = 0;
        $h    = $this->totalBlocks;
        $dataPtr = 0;
        while ( $l <= $h )
        {
            $m    = (($l + $h) >> 1);
            $p    = $m * INDEX_BLOCK_LENGTH;

            fseek($this->dbFileHandler, $this->firstIndexPtr + $p);
            $buffer = fread($this->dbFileHandler, INDEX_BLOCK_LENGTH);
            $sip    = self::getLong($buffer, 0);
            if ( $ip < $sip ) {
                $h    = $m - 1;
            } else {
                $eip = self::getLong($buffer, 4);
                if ( $ip > $eip ) {
                    $l = $m + 1;
                } else {
                    $dataPtr = self::getLong($buffer, 8);
                    break;
                }
            }
        }

        //not matched just stop it here
        if ( $dataPtr == 0 ) return NULL;


        //get the data
        $dataLen = (($dataPtr >> 24) & 0xFF);
        $dataPtr = ($dataPtr & 0x00FFFFFF);

        fseek($this->dbFileHandler, $dataPtr);
        $data     = fread($this->dbFileHandler, $dataLen);

        return array(
            'city_id' => self::getLong($data, 0),
            'region'  => substr($data, 4)
        );
    }

    /**
     * get the data block associated with the specifield ip with b-tree search algorithm
     *
     * @param    ip
     * @return    Mixed Array for NULL for any error
     */
    public function btreeSearch( $ip )
    {
        if ( is_string($ip) ) $ip = ip2long($ip);

        //check and load the header
        if ( $this->HeaderSip == NULL )
        {
            fseek($this->dbFileHandler, 8);
            $buffer    = fread($this->dbFileHandler, TOTAL_HEADER_LENGTH);

            //fill the header
            $idx = 0;
            $this->HeaderSip = array();
            $this->HeaderPtr = array();
            for ( $i = 0; $i < TOTAL_HEADER_LENGTH; $i += 8 )
            {
                $startIp = self::getLong($buffer, $i);
                $dataPtr = self::getLong($buffer, $i + 4);
                if ( $dataPtr == 0 ) break;

                $this->HeaderSip[] = $startIp;
                $this->HeaderPtr[] = $dataPtr;
                $idx++;
            }

            $this->headerLen = $idx;
        }

        //1. define the index block with the binary search
        $l = 0; $h = $this->headerLen; $sptr = 0; $eptr = 0;
        while ( $l <= $h )
        {
            $m = (($l + $h) >> 1);

            //perfetc matched, just return it
            if ( $ip == $this->HeaderSip[$m] ) {
                if ( $m > 0 ) {
                    $sptr = $this->HeaderPtr[$m-1];
                    $eptr = $this->HeaderPtr[$m  ];
                } else {
                    $sptr = $this->HeaderPtr[$m ];
                    $eptr = $this->HeaderPtr[$m+1];
                }

                break;
            }

            //less then the middle value
            if ( $ip < $this->HeaderSip[$m] ) {
                if ( $m == 0 ) {
                    $sptr = $this->HeaderPtr[$m  ];
                    $eptr = $this->HeaderPtr[$m+1];
                    break;
                } else if ( $ip > $this->HeaderSip[$m-1] ) {
                    $sptr = $this->HeaderPtr[$m-1];
                    $eptr = $this->HeaderPtr[$m  ];
                    break;
                }
                $h = $m - 1;
            } else {
                if ( $m == $this->headerLen - 1 ) {
                    $sptr = $this->HeaderPtr[$m-1];
                    $eptr = $this->HeaderPtr[$m  ];
                    break;
                } else if ( $ip <= $this->HeaderSip[$m+1] ) {
                    $sptr = $this->HeaderPtr[$m  ];
                    $eptr = $this->HeaderPtr[$m+1];
                    break;
                }
                $l = $m + 1;
            }
        }

        //match nothing just stop it
        if ( $sptr == 0 ) return NULL;

        //2. search the index blocks to define the data
        $blockLen = $eptr - $sptr;
        fseek($this->dbFileHandler, $sptr);
        $index = fread($this->dbFileHandler, $blockLen + INDEX_BLOCK_LENGTH);

        $dataptr = 0;
        $l = 0; $h = $blockLen / INDEX_BLOCK_LENGTH;
        while ( $l <= $h ) {
            $m = (($l + $h) >> 1);
            $p = (int)($m * INDEX_BLOCK_LENGTH);
            $sip = self::getLong($index, $p);
            if ( $ip < $sip ) {
                $h = $m - 1;
            } else {
                $eip = self::getLong($index, $p + 4);
                if ( $ip > $eip ) {
                    $l = $m + 1;
                } else {
                    $dataptr = self::getLong($index, $p + 8);
                    break;
                }
            }
        }

        //not matched
        if ( $dataptr == 0 ) return NULL;

        //3. get the data
        $dataLen = (($dataptr >> 24) & 0xFF);
        $dataPtr = ($dataptr & 0x00FFFFFF);

        fseek($this->dbFileHandler, $dataPtr);
        $data = fread($this->dbFileHandler, $dataLen);

        return array(
            'city_id' => self::getLong($data, 0),
            'region'  => substr($data, 4)
        );
    }

    /**
     * read a long from a byte buffer
     *
     * @param    b
     * @param    offset
     */
    public static function getLong( $b, $offset )
    {
        return (
            (ord($b[$offset++]))        |
            (ord($b[$offset++]) << 8)    |
            (ord($b[$offset++]) << 16)    |
            (ord($b[$offset  ]) << 24)
        );
    }

    /**
     * destruct method, resource destroy
     */
    public function __destruct()
    {
        if ( $this->dbFileHandler != NULL ) fclose($this->dbFileHandler);
        $this->HeaderSip = NULL;
        $this->HeaderPtr = NULL;
    }
}
?>