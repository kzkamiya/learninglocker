<?php
/**
 * Used to handle an LRSs 3 document APIs.
 *
 **/

use Jenssegers\Mongodb\Model as Eloquent;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Locker\Repository\Document\FileTypes;

class DocumentAPI extends Eloquent {
  protected $collection = 'documentapi';
  protected $hidden = ['_id', 'created_at', 'updated_at', 'lrs', 'apitype'];

  /**
   * Returns true if $array is associative.
   * @param Array $array
   * @return boolean      
   */
  private function isJSON($array) {
    return is_array($array) && array_keys($array) !== range(0, count($array) - 1);
  }

  private function putContent($content, $contentType) {
    switch ($contentType) {
      case 'application/json':
        $this->setSha($this->content);
        $this->overwriteContent(json_decode($content, true));
        break;
      case 'text/plain':
        $this->setSha($content);
        $this->overwriteContent($content);
        break;
      default: $this->saveDocument($content, $contentType);
    }
  }

  private function ammendmentError() {
    return new \Exception(
      "Cannot amend existing {$this->contentType} document with a string"
    );
  }

  private function postContent($content, $contentType) {

    if( $this->exists ){
      $decoded_content = json_decode($content, true);
      //Check existing content type and incoming content type are both application/json
      if ( $this->contentType !== 'application/json' || $contentType !== 'application/json' ) {
        throw new \Exception('Both existing content type and incoming content type must be application/json');
      }
      //Check existing content and incoming content are both JSON
      if ( !$this->isJSON($this->content) || !$this->isJSON($decoded_content)  ) {
        throw new \Exception('Both existing content and incoming content must be parsable as JSON in order to use POST');
      }

      //Merge JSON
      $this->mergeJSONContent($decoded_content, $contentType);
    } else {
      //If document does not already exist, treat as PUT
      $this->putContent($content, $contentType );
    }
  }

  private function setSha($content) {
    $this->sha = '"'.strtoupper(sha1($content)).'"';
  }

  private function mergeJSONContent($content, $contentType) {
    if (!$this->isJSON($content)) {
      throw new \Exception(
        'JSON must contain an object at the top level.'
      );
    } else if ($this->contentType !== $contentType) {
      throw new \Exception(
        'JSON document content may not be merged with that of another type'
      );
    }
    $this->content = array_merge($this->content, $content);
    $this->setSha(json_encode($this->content));
  }

  private function overwriteContent($content) {
    $this->content = $content;
  }

  private function saveDocument($content, $contentType) {
    $dir = $this->getContentDir();

    if ($content instanceof UploadedFile) {
      $origname = $content->getClientOriginalName();
      $parts = pathinfo($origname);
      $filename = Str::slug(Str::lower($parts['filename'])).'-'.time().'.'.$parts['extension'];
      $content->move($dir, $filename);
    } else {
      $ext = array_search($contentType, FileTypes::getMap());
      
      $filename = time().'_'.mt_rand(0,1000).($ext !== false ? '.'.$ext : '');

      $size = file_put_contents($dir.$filename, $content);

      if ($size === false) throw new \Exception('There was an issue saving the content');
    } 

    $this->content = $filename;
    $this->setSha($content);
  }


  /**
   * Handle content storage
   * @param Mixed $content          The content passed in the request
   */
  public function setContent( $content_info, $method){
    $content      = $content_info['content'];
    $contentType  = $content_info['contentType'];

    $contentTypeArr = explode(";", $contentType);
    if( sizeof($contentTypeArr) >= 1 ){
      $mimeType = $contentTypeArr[0];
    } else {
      $mimeType = $contentType;
    }

    if ($method === 'PUT') {
      $this->putContent($content, $mimeType);
    } else if ($method === 'POST') {
      $this->postContent($content, $mimeType);
    }

    $this->contentType = $mimeType;

  }

  public function getContentDir(){
    $dir = base_path().'/uploads/'.$this->lrs.'/documents/';
    if( !file_exists($dir) ){
      mkdir( $dir, 0774, true );
    }

    return $dir;
  }

  public function getFilePath(){
    return $this->getContentDir() . $this->content;
  }

}