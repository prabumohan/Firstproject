<?php

abstract class Database
{
  private $conn ;

  abstract public function Connect( $host, $Database, $Username, $Password ) ;
  abstract public function Disconnect( ) ;

  abstract public function PrepareSQL( $sql ) ; // returns $Resource
  abstract public function ExecSQL( $Resource ) ; // return $ResultSet

  abstract public function RecordCount( $ResultSet ) ;
  abstract public function RowExists( $ResultSet ) ;
  abstract public function FetchRow( $ResultSet ) ;

  #abstract public function dbIntToDateTimeStr( $DateTime ) ;
  #abstract public function dbDateTimeStrToInt( $DateTimeStr ) ;
  #abstract public function dbDateTimeSecStrToInt( $DateTimeStr ) ;

  #abstract function GetMaxMinute( $field, $table, $filter="" ) ;
  #abstract function GetMinMinute( $field, $table, $filter="" ) ;
  #abstract function GetDBDateTime( ) ;
  #abstract function GetDBDateTimeSec( ) ;
}

#------------------------------------------------------------------------
#------------------------------------------------------------------------

class Oracle extends Database
{
  private $conn ;

  public function Connect( $host, $Database, $Username, $Password ) 
  {
    putenv("NLS_LANG=American_America.UTF8");
    $this->conn = oci_connect( $Username, $Password, '//'.$host.':1521/'.$Database);
    if (!$this->conn) {
      print "Host:".$host." Database:".$Database."\n" ;
      $e = oci_error();
      die('Error connecting to Oracle:'.$e."\n");
    }
  }

  public function ConnectORA( $Database, $Username, $Password )
  {
    putenv("NLS_LANG=American_America.UTF8");
    putenv("TNS_ADMIN=/etc/oracle");
    $this->conn = oci_connect( $Username, $Password, $Database);
    if (!$this->conn) {
      print "Database:".$Database."\n" ;
      $e = oci_error();
      die('Error connecting to Oracle:'.$e."\n");
    }
  }

  public function Disconnect( ) 
  {
    oci_close($this->conn);
  }

  public function PrepareSQL( $sql ) 
  {
     $stid = oci_parse($this->conn, $sql);
     if (!$stid) {
       $e = oci_error($conn);
       print "SQL:".$sql."\n" ;
       die( $e['message']."\n");
       exit;
     }
     return( $stid ) ;
  }

  public function ExecSQL( $Resource ) 
  {
     $r = oci_execute($Resource, OCI_DEFAULT);
     if (!$r) {
       $e = oci_error($Resource);
       die($e['message']."\n");
     }
     return( $Resource ) ;
  }

  public function RecordCount($ResultSet) 
  {
    return( oci_num_rows($ResultSet)) ;
  }

  public function RowExists( $ResultSet ) 
  {
    return( oci_fetch_array($ResultSet, OCI_ASSOC) ) ;
  }

  public function FetchRow( $ResultSet ) 
  {
    return( oci_fetch_array($ResultSet, OCI_ASSOC) ) ;
  }

  #public function dbIntToDateTimeStr( $datetime )
  #{
  #  $format = '%d/%m/%Y %H:%M:%S';
  #  return( strftime($format, $datetime));
  #}

  #public function dbDateTimeStrToInt( $DateTimeStr, $format='%d/%m/%Y %H:%M:%S' ) 
  #{
    //$format = '%d/%m/%Y %H:%M:%S';
  #  $Result = strptime($DateTimeStr, $format);

   # return( mktime($Result['tm_hour'], $Result['tm_min'],0,
    #               $Result['tm_mon'] + 1, $Result['tm_mday'], $Result['tm_year'] + 1900 ) );
  #}

  #public function dbDateTimeSecStrToInt( $DateTimeStr, $format='%d/%m/%Y %H:%M:%S' )
  #{
    //$format = '%d/%m/%Y %H:%M:%S';
   # $Result = strptime($DateTimeStr, $format);

    #return( mktime($Result['tm_hour'], $Result['tm_min'], $Result['tm_sec'],
     #              $Result['tm_mon'] + 1, $Result['tm_mday'], $Result['tm_year'] + 1900 ) );
  #}

 # public function GetMaxMinute( $field, $table, $filter="" )
 # {
  #  $sql="select to_char( max( ".$field." ), 'dd/mm/yyyy hh24:mi:ss') BIGONE from ".$table." ".$filter ;
  #  $Resource = $this->PrepareSQL( $sql ) ;
   # $Result=$this->ExecSQL( $Resource ) ;
   # $row=$this->FetchRow( $Result );
    #print $row['BIGONE']."\n" ;
    #return( $this->dbDateTimeStrToInt( $row['BIGONE'] )) ;
  #}

  #public function GetMinMinute( $field, $table, $filter="" )
  #{
   # $sql="select to_char( min( ".$field." ), 'dd/mm/yyyy hh24:mi:ss') BIGONE from ".$table." ".$filter ;
   # $Resource = $this->PrepareSQL( $sql ) ;
    #$Result=$this->ExecSQL( $Resource ) ;
    #$row=$this->FetchRow( $Result );
    #print $row['BIGONE']."\n" ;
    #return( $this->dbDateTimeStrToInt( $row['BIGONE'] )) ;
  #}

  #public function GetDBDateTime( ) 
  #{
   # $sql="select to_char(SYSDATE, 'dd/mm/yyyy hh24:mi:ss') timestamp from dual" ;
    #$Resource = $this->PrepareSQL( $sql ) ;
    #$Result=$this->ExecSQL( $Resource ) ;
    #$row=$this->FetchRow( $Result );
    #print $row['TIMESTAMP']."\n" ;
    #return( $this->dbDateTimeStrToInt( $row['TIMESTAMP'] )) ;
  #}

  #public function GetDBDateTimeSec( )
  #{
   # $sql="select to_char(SYSDATE, 'dd/mm/yyyy hh24:mi:ss') timestamp from dual" ;
   # $Resource = $this->PrepareSQL( $sql ) ;
   # $Result=$this->ExecSQL( $Resource ) ;
   # $row=$this->FetchRow( $Result );
    #print $row['TIMESTAMP']."\n" ;
  #  return( $this->dbDateTimeSecStrToInt( $row['TIMESTAMP'] )) ;
# }#

}

#------------------------------------------------------------------------
#------------------------------------------------------------------------

class MySQL extends Database
{
  private $conn ;

  public function Connect( $host, $Database, $Username, $Password ) 
  {
    $this->conn = mysql_connect($host, $Username, $Password, TRUE);
    if ( ! $this->conn )
    {
      print "Host:".$host." Database:".$Database."\n" ;
      $e=mysql_error( $this->conn ) ;
      die('Error connecting to MYSQL:'.$e."\n");
    }
    mysql_select_db($Database, $this->conn);
  }

  public function Disconnect( ) 
  {
    if(isset($this->conn)){
       mysql_close($this->conn);
    }
  }

  public function PrepareSQL( $sql ) 
  {
    return( $sql);
  }

  public function ExecSQL( $ResourceID ) 
  {
    if ( ! $result = mysql_query( $ResourceID, $this->conn ) ) 
    {
      print "SQL:".$ResourceID."\n" ;
      $e=mysql_error( $this->conn ) ;
      die('Error connecting to MYSQL:'.$e."\n");
    }
    else
    {
      return( $result ) ;
    }
  }

  public function RecordCount( $ResultSet ) 
  {
    mysql_numrows($ResultSet);
  }

  public function RowExists( $ResultSet ) 
  {
    return( mysql_fetch_array( $ResultSet, MYSQL_ASSOC ) ) ;
  }

  public function FetchRow( $ResultSet ) 
  {
    return( mysql_fetch_array( $ResultSet, MYSQL_ASSOC ) ) ;
  }

 public function dbIntToDateTimeStr( $datetime )
  {
    $format = '%Y-%m-%d %H:%M:%S';
    return( strftime($format, $datetime));
  }

  #public function dbDateTimeStrToInt( $DateTimeStr, $format='%Y-%m-%d %H:%M:%S' ) 
  #{
    //$format = '%Y-%m-%d %H:%M:%S';
   # $Result = strptime($DateTimeStr, $format);
   # if ( ! $Result )
   # {
   #   $format = '%Y-%m-%d %H-%M-%S';
   #   $Result = strptime($DateTimeStr, $format);
   # }
   # return( mktime($Result['tm_hour'], $Result['tm_min'],0,
   #                $Result['tm_mon'] + 1, $Result['tm_mday'], $Result['tm_year'] + 1900 ) );
  #}

  #public function dbDateTimeSecStrToInt( $DateTimeStr, $format='%Y-%m-%d %H:%M:%S' )
  #{
    //$format = '%Y-%m-%d %H:%M:%S';
   # $Result = strptime($DateTimeStr, $format);
   # if ( ! $Result )
    #{
    #  $format = '%Y-%m-%d %H-%M-%S';
    #  $Result = strptime($DateTimeStr, $format);
    #}
    #return( mktime($Result['tm_hour'], $Result['tm_min'], $Result['tm_sec'],
     #              $Result['tm_mon'] + 1, $Result['tm_mday'], $Result['tm_year'] + 1900 ) );
  #}

  #function GetMaxMinute( $field, $table, $filter="" )
  #{
  #  $sql="select max( ".$field." ) BIGONE from ".$table." ".$filter ;
  #  $Resource = $this->PrepareSQL( $sql ) ;
  #  $Result=$this->ExecSQL( $Resource ) ;
  #  $row=$this->FetchRow( $Result );
  #  #print $row['BIGONE']."\n" ;
  #  return( $this->dbDateTimeStrToInt( $row['BIGONE'] )) ;
  #}

  #function GetMinMinute( $field, $table, $filter="" )
  #{
  #  $sql="select min( ".$field." ) BIGONE from ".$table." ".$filter ;
  #  $Resource = $this->PrepareSQL( $sql ) ;
  #  $Result=$this->ExecSQL( $Resource ) ;
  #  $row=$this->FetchRow( $Result );
  #  #print $row['BIGONE']."\n" ;
  #  return( $this->dbDateTimeStrToInt( $row['BIGONE'] )) ;
 # }

  #public function GetDBDateTime() 
  #{
  #  $sql="select now() timestamp" ;
   # $Resource = $this->PrepareSQL( $sql ) ;
   # $Result=$this->ExecSQL( $Resource ) ;
   # $row=$this->FetchRow( $Result );
    #print $row['timestamp']."\n" ;
   # return( $this->dbDateTimeStrToInt( $row['timestamp'] )) ;
  #}

  #public function GetDBDateTimeSec()
  #{
  #  $sql="select now() timestamp" ;
  #  $Resource = $this->PrepareSQL( $sql ) ;
   # $Result=$this->ExecSQL( $Resource ) ;
   # $row=$this->FetchRow( $Result );
    #print $row['timestamp']."\n" ;
  #  return( $this->dbDateTimeSecStrToInt( $row['timestamp'] )) ;
  #}

}

?>
