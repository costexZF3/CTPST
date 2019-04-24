<?php

namespace Purchasing\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use Zend\Session\Container as SM;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Xls;


/* services injected from the FACTORY: WishListControllerFactory */
use Purchasing\Service\WishListManager;
use Application\Service\QueryManager;


/* MODEL: forms classes */
use Purchasing\Form\FormAddItemWL as FormAddItemWL;
use Purchasing\Form\FormUpload; 
use Purchasing\Form\FormValidator;


class WishlistController extends AbstractActionController 
{    
     /**
      * VARIABLE QueryManager is an instance of the service
      * @var WishListManager 
      */
     private $WLManager = null; 

     /**
      * @var queryManager
      */
      private $queryManager; 
      
      /**
       *
       * @var Zend\Session\Container
       */
      private $session;
     
      /**
      * @param WishListManager $wlManager  | WishList service injected from the Factory
      * @param QueryManager $queryManager  | WishList service injected from the Factory
      * 
      */
      public function __construct( WishListManager $wlManager, 
                                   QueryManager $queryManager,
                                   SM $sessionManager ) 
      {           
         $this->WLManager = $wlManager;   
         $this->queryManager = $queryManager;
         $this->session = $sessionManager;
      }   
    
      /*
      * getting the logged user 
      */
     private function getUser()
     {
         $user = $this->currentUser();       
         //validating the user
         if ($user == null) {
             $this->getResponse()->setStatusCode( 404 );
             return;
         } 
         return $user;
     }//End: getUser()      
     
   
    /**
     *  The IndexAction show the WishList 
     */
    public function indexAction() 
    {
      $this->layout()->setTemplate('layout/layout_Grid');
      $linkInc = $this->session->inconsistency ?? false;
      
      return new ViewModel(
              ['wldata' => $this->WLManager->TableAsHtml(),
                  'url1' => $linkInc //$urlinc  
              ]);
    }//END: indexAction method
    
 
    /**
     * THIS METHOD IS AND ACTION upload defined in the module.config.php
     * @return ViewModel
     */
    public function uploadAction() 
    {
        $form = new FormUpload( $this->queryManager );
       
        if( $this->getRequest()->isPost() ) {
            
            // Make certain to merge the files info!
            $request = $this->getRequest();
            
            /* you should remember three things: 1) merge $_POST and $_FILES super-global arrays 
               before you pass them to the form's setData() method; 2) use isValid() form's method 
                to check uploaded files for correctness (run validators); 3) use getData() form's method 
                to run file filters. */            
            $data = array_merge_recursive(
                $request->getPost()->toArray(),  //getting data from Post of Elements
                $request->getFiles()->toArray()  //getting data from Files Elements (like: name of the file, etc )
            );
            
            // Pass data to form.
            $form->setData($data);
            
            // Execute file validators. INVERSE TO THE NORMAL PROCESS 
            if ($form->isValid()) {                                
                $data = $form->getData();
                
                $inputFileName = $data['file']['tmp_name']; //recovering from the route defined
                
                //reading from file
                $sheetData = $this->readExcelFile( $inputFileName );
                
                //result['valid'] : data ready for updating  and result['invalid'] : inconsistency data
                if (!empty( $sheetData )) {
                    $result = $this->parseExcelFile( $sheetData );
                } 
                
                $existPartsToInsert = !empty( $result['valid'] ); // are there some Parts ready for being inserted?
                $existInc = !empty($result['novalid']); // are there inconsistencies???
                
                if ( $existPartsToInsert ) {
                    $inserted = $this->insertValid( $result['valid'] );
                    
                    if ( $inserted ) {                        
                        $this->flashMessenger()->addInfoMessage("The PARTS from file: [".$data['file']['name']."] were uploaded.[ ".count($result['valid'])." ] item(s) was(were) uploaded.");
                    } else {
                        $this->flashMessenger()->addErrorMessage("Oopss!! Error trying uploading files.");
                    }
                }
                
                /* CREATE INCONSISTENCY EXCEL  */
                if ( $existInc ) {
                    $this->updateErrorsInXls( $result['novalid'] );
                    $urlInc = './data/upload/wishlist_inc.xls';  
                    $message = !$existPartsToInsert ? 'No PARTS were uploaded' : '';
                    $this->flashMessenger()->addErrorMessage("Oopss!!! [".count($result['novalid'])."] inconsistencies were found. ". $message);                            
                }
               
                $options = ['urlinc'=> $urlInc];
                return $this->redirect()->toRoute('wishlist', ['action'=>'index'], $options, $options);          
                                
            } else {
                 $form->get('file')->setMessages(['Oops!!! Invalid file format']); 
            }//ENDIF isValid the File???
    
        }//ENDIF checking isPost()  
        
        // Render the page.
        return new ViewModel([
                 'form' => $form
            ]);       
    }//END METHOD: 
    
    
    public function createAction() 
    {   
        $this->session->fromExcel = false;
        //creating an instance of form to Map data
        $form = new FormAddItemWL( 'create', $this->queryManager );
        
        if ($this->getRequest()->isPost() ) {
            /* getting data from the form*/
            $data = $this->params()->fromPost();
            
            $form->setData( $data );            
            
            //all data are ok then they are ready for being inserted
            if ($form->isValid()) {           
               $data = $form->getData(); //retrieving filtered data
                              
               //IT'S TIME TO UPDATE CATEGORY AND SUBCATEGORY FROM SESSION 
               $data['category'] = $this->session->category[$data['minor']];
               $data['subcategory'] = $this->session->subcategory[$data['minor']];
               $data['from'] = WishListManager::FROM_MANUAL;
              
               $this->insert( $data );
                              
            } else { //it was an error
                if ( $form->get('major')->getMessages() !== null ) {
                   $form->get('major')->setMessages(['Invalid code!']); 
                }
            }
            
        }//ENDIF: getting data by POST
        
        $partnumber = $this->session->part; 
       //$table = $this->session->table;
             
        if ($partnumber == null) {
            $this->redirect()->toRoute('wishlist');
        }
            
        //recover data from FILE (CATER or KOMAT). Manipulate it across session variables
        $data = $this->getInfo( $partnumber );               
        $minors = $this->getMinors();

        $data['user'] = str_replace('@costex.com', '', $this->getUser()->getEmail());
        $data['date'] = date('Y-m-d');
        $data['code'] = $this->queryManager->getMax('WHLCODE', 'PRDWL');

        //injecting all minor codes into the HTML SELECT ELEMENT
        $form->get('minor')->setValueOptions( $minors );        
        
        //updating the $form data before show it.
        $form->setData( $data );
        
        return new ViewModel(['form'=>$form]);
        
    }//END: METHOD create
    
    
    /* 
    * adding: Adding a new Item to WishList
    */
    public function addAction()
    {     
        //scenario 1  
        $form = new FormAddItemWL( 'initial', $this->queryManager );

        //checking it the request was by POST()
        if ($this->getRequest()->isPost()) {              
            $data = $this->params()->fromPost();

            //checking which type or instance of ForAddItemWL we MUST CREATE
            $initial = $data['submit']=='SUBMIT';
            
            // if not INITIAL THEN an ADD button was pressed
            if ( !$initial ) {                
                $form = new FormAddItemWL( 'insert', $this->queryManager );
            }

            /* it's a MUST BEFORE validating */
            $form->setData( $data ); 
             
           /* valid if not exit in WISHLIST, THEN I can insert it inside de PRDWL */ 
           if ( $initial && $form->isValid() ) {                   
               //getting filtered data 
               $data = $form->getData();   

               $partnumber = $data['partnumber'];

               //it's determine if the part it's ready to insert in the wishlist
               $inStock = $this->existPartInventory( $partnumber );               
               
               if (isset($inStock['noinventory'])) {
                    $form->get('partnumber')->setMessages(['Oops!!! It does not Exist in our Inventory.']);     
                   return new ViewModel(['form' => $form ]);
               }
                             
               $result = $this->findOutScenario( $partnumber, $inStock );
           
               if (empty( $result )) {
                  return $this->redirect()->toRoute('wishlist', ['action'=>'create']);  
               }
               return new ViewModel( $result );
             
           } elseif ( $form->isValid() ) {
                /* get data filtered*/
               $data = $form->getData();              
               $data['from'] = WishListManager::FROM_MANUAL;
               
               //inserting THE DATA OF THE FORM INTO WISHLIST
               $this->insert( $data );           
           }//END ELSEIF
        }//END: IF isPost()  

        return new ViewModel([
             'form' => $form,            
        ]);

    }//END: METHOD
  
    
    /* ==================================== PRIVATE FUNCTIONS ====================================*/  
          
      /**
       * @param string $table
       */
      private function getValidator( $table ) 
      {     
           $options = [
                 'table' => $table,
                 'queryManager' => $this->queryManager                                  
            ];
           
          $partValid = new \Application\Validator\PartNumberValidator( $options );
          
          return $partValid;
      }
      
      
    /** 
     * This method return Minors and CATEGORY AND SUBCATEGORY ASSOCIATED TO THE MINOR
     * @return array() | Create sessions variables: category[] and subcategory[]  
     */
    private function getMinors() 
    {
        $sqlStr = "SELECT CNTDE1, CNMCOD, CNMCAT, CNMSBC FROM CNTRLM";
        $data = $this->queryManager->runSql( $sqlStr );        
        if ($data !== null ) {            
            for ( $i=0; $i<count($data); $i++) {                               
               $result[$data[$i]['CNMCOD']] = $data[$i]['CNMCOD'];      //MINOR
               $category[$data[$i]['CNMCOD']] = $data[$i]['CNMCAT'];    //CATEGORY
               $subcategory[$data[$i]['CNMCOD']] = $data[$i]['CNMSBC']; //SUBCATEGORY       
            }//end for
            
            $this->session->minors = $result;
            $this->session->category = $category;
            $this->session->subcategory = $subcategory;        
        }//end if        
        
        return $result;
    }//end: getMinors() method
    
      /**
       * This method retrieves information (PartNumber, Description, Price, Major
       * about parts in CATER or KOMAT
       * @param string $partnumber
       * @return array Description
       */
    private function getInfo( $partnumber ): array 
    {
        //retrieving CATER or KOTAM from the session 
        $table = $this->session->table;
                          
        $field = $table == 'CATER' ? 'CATPTN' : 'KOPTNO';
        $decriptionField = $table == 'CATER' ? 'CATDSC' : 'KODESC';
        $priceField = $table == 'CATER' ? 'CATPRC' : 'KOPRIC';
        
        $sqlStr = "SELECT * FROM ".$table." WHERE ".$field." = '".strtoupper( $partnumber )."'";
        $data = $this->queryManager->runSql( $sqlStr );
      
        if ($data !== null ) {
            $result['partnumber'] = $partnumber;
            $result['partnumberdesc'] = $data[0][$decriptionField];
            $result['price'] = round($data[0][$priceField], 2);
            $result['major'] = ($table == 'CATER') ? '01':'03';
        }
    
        return $result;
    }//END: METHOD getInfo()
    
    
    /**
     * The method returns an array showing whether partnumber exist, and within
     * which table, in other case (it does not exist) return in the index: 'noinventory' == true
     * 
     * @param string $partnumber | Part number which we need to find out 
     * @return array()
     */
    private function existPartInventory ( $partnumber ) 
    {
        $partInINMSTA = $this->getValidator('INMSTA');                
        $partInCATER = $this->getValidator('CATER');                
        $partInKOMAT = $this->getValidator('KOMAT');                

        /*checking if the PARTNUMBER is part of : INMSTA, CATER, or KOMAT
         * - in case the partnumber does not exist, then change the Error Message and 
         * show it.
         */
        $result['INMSTA'] = $partInINMSTA->isValid( $partnumber ); 
        $result['CATER'] = $partInCATER->isValid( $partnumber ); 
        $result['KOMAT'] = $partInKOMAT->isValid( $partnumber ); 
        
        $existInvetory = $result['INMSTA'] || $result['CATER'] || $result['KOMAT'];

        if ( !$existInvetory ) {
            $result['noinventory'] = true; 
            return $result;            
        }        
        
        return $result;
    }//END METHOD: existPartInventory()
    
    
    /**
     *  This Method update the PartNumber and table in the session
     * @param string $partnumber
     * @param string $table
     */
    private function updateSession( $partnumber, $table ) 
    {
        $this->session->part = $partnumber;       
        $this->session->table = $table;
    }
        
    /**
    *  THIS METHOD USE THE WISHLIST MANAGER TO INSERT A LIST OF ITEMS
    * @param array $data | this method calls to the insert method of the WishList Manager 
    */  
    private function insert( $data ) 
    {
        $inserted = $this->WLManager->insert( $data );
        
        
        if (!$this->session->fromExcel) {
            if ( $inserted ) {
                //update flashmessenger INSERTION WAS OK     
                $this->flashMessenger()->addSuccessMessage(
                         "The new part has been successfuly inserted CODE: [".$data['code']."]");

                $this->session->part = null;
                $this->redirect()->toRoute('wishlist');

            } else {
                $this->flashMessenger()->addErrorMessage(
                            'Oops!!! Could not be inserted the new part number in the Wish List.');

            } 
        } else {
          $this->session->countUploaded++;  
        }
        
        return $inserted === true;
    }//END METHOD: insert();  
    
    /**
     * 
     * @return string | It returns the short form of the user
     */
    private function getUserS() 
    {
        $strUser = str_replace('@costex.com', '', $this->getUser()->getEmail());
        return $strUser;
    }
    
    private function findOutScenario( $partnumber, $inStock  ) 
    {
        if ( $inStock['INMSTA'] ) {

            //get data from the partnumber 
            $data = $this->WLManager->getDataItem( $partnumber );       

            /* check if there was some error or the part does not exist in INMSTA */
             if ( !isset($data['error']) ) {
                 $data['user'] = $this->getUserS(); //str_replace('@costex.com', '', $this->getUser()->getEmail());  

                 /* creating FORM for scenario 2 */
                 $form = new FormAddItemWL( 'insert', $this->queryManager );
                 $form->setData( $data );

                 return ['form' => $form, 'renderAll'=> true ];

             } //not error getting info from IMSTA
        }// END: THE PART IS INSIDE INMSTA 
               
        // SCENARIO 3 CATER OR KOMAT
        if ( $inStock['CATER'] ) { //saving the PARTNUMBER into the session variable: part
            $this->updateSession($partnumber, 'CATER');                              
            return [];
            //return $this->redirect()->toRoute('wishlist', ['action'=>'create']);                       
        }         

        /* if the part number exist on CATER or KAMAT, then create part */
        if (  $inStock['KOMAT'] ) { //saving the PARTNUMBER into the session variable: part

            $this->updateSession($partnumber, 'KOMAT'); 
            return [];
            //return $this->redirect()->toRoute('wishlist', ['action'=>'create']);                       
        }
    }//END METHOD: findOutScenario()
    
    
    /**
     * 
     * @param array() $inStock
     * @return string it returns the table Name (IMNSTA, KOMAT, or CATER) where the part is.
     */
    private function whichTable( $inStock ) 
    {
        if ( $inStock['INMSTA'] == 1) { 
            return 'INMSTA';
        } else if ( $inStock['CATER'] == 1) {
            return 'CATER';
        }

        return 'KOMAT';
    }
      
    /**
     * This method removes from the VALID PARTS all with a no valid MINOR CODE
     *  - the parts inside KOMAT and CATER will be checked
     * @param array() $validParts
     * @param array() $noValidParts
     * @return array()  
     */
    private function inconsistencyByMinorRemove( $validParts, $noValidParts) 
    {
        // checking if the minors are loaded withing the sessionManager
        // if not, then we need to load them 
        if ( !$this->session->minors  ) {
            $minors = $this->getMinors(); 
        } else {
            $minors = $this->session->minors;
        }
        
        //check inconsistencies by MINORS CODE
        foreach ( $validParts as $key => $row ) {            
            $table = $row['table'];
            $minor = strtoupper($row['minor'])??''; //if defined then assign it
            
            //checking if the minor code is valid
            if ( in_array( $table, ['CATER', 'KOMAT'])) {              
                //check the minor code 
                 
                if (!in_array( $minor, $minors )){  // no valid MINORS
                    $validParts[$key]['error'] = 'INVALID MINOR';
                    
                    //inserting into $noValidParts[]
                    array_push($noValidParts, $validParts[$key]);                 
                    
                    // removing from $validParts[]
                    unset($validParts[$key]); 
                } else { //update other properties
                    $validParts[$key]['category'] = $this->session->category[$minor];
                    $validParts[$key]['subcategory'] = $this->session->subcategory[$minor];
                }
            }                  
        }//end foreach        
        
        $result['valid'] = $validParts;
        $result['novalid'] = $noValidParts;
        return $result;
    }// END inconsistencyByMinorRemove()
  
    /**
     * Auxiliary method for updating NoValid Parts
     * - invoque for partExcelFile method()
     * @param int $cod
     * @param string $partnumber
     * @param string $error
     * @param array() $noValid
     */
    private function updateNoValid( $cod, $partnumber, $error ) {
        
        $noValidParts['code'] =  $cod;
        $noValidParts['partnumber']= $partnumber;
        $noValidParts['error']= $error;
        
        return $noValidParts;
    }
    
    /**     
     * @param array() $sheetData | it is an array which contains the new records trying to insert to the WL
     * @return array() | it returns and array['valid', 'novalid']
     */
    private function parseExcelFile( $sheetData ) 
    {        
        $noValidParts = []; $validParts = [];        
        $form = new FormValidator( $this->queryManager );
        
        foreach ($sheetData as $key => $row ) {                       
           $partnumber = trim( $row['B']); 
            //it's determine if the part it's ready to insert in the wishlist
           $inStock = $this->existPartInventory( $partnumber );
           
           $data['partnumber'] =  $partnumber;         
           
           //updating form data for  
           $form->setData( $data );
           
           //checking the part number does not exist within the WL (PRDWL). 
           //It will be truth whether the part already exist
           if ( !$form->isValid() ) {
                $errorCode = key($form->get('partnumber')->getMessages());             
                $noValidParts[$key] = $this->updateNoValid($row['A'], $partnumber, $errorCode);               
                                
            } else if (isset ($inStock['noinventory'])) {   //checking that the PART be a VALID PART (it be withing INMSTA, CATER, KOMAT)
                $errorCode = \Application\Validator\PartNumberValidator::INVALID_PARTNUMBER;
                $noValidParts[$key] = $this->updateNoValid($row['A'], $partnumber, $errorCode);              
                
            } else { // the partnumber is ready for being inserted into WL
                $validParts[$key]['code'] = $row['A'];  
                $validParts[$key]['partnumber'] = $partnumber;
                $validParts[$key]['minor'] = $row['C'];  //this MUST BE  a valid MINOR CODE
                $validParts[$key]['table'] = $this->whichTable( $inStock ); //'C' it contains the name of the source table 
            }
        }//endforeach
                     
        $parsedlist = $this->inconsistencyByMinorRemove( $validParts, $noValidParts);
        
        return $parsedlist;
    }//END METHOD: parseExcelFile()
        
    /**
     * This method insert parts with no INCONS
     * @param array() $listValid  | List of valid parts will be inserted in the WL
     */
    private function insertValid( $listValid ) 
    {               
        $this->session->countUploaded = 0; 
        $caterKomat = false;
        
        $data =[];
        
       //loading data of each part number depending on where they comes from.
        foreach ( $listValid as $key => $row ) {            
            
            $data['code'] = $this->WLManager->nextIndex();
            $data['user'] = $this->getUserS();
            $data['partnumber'] = $listValid[$key]['partnumber'];            
            $data['type'] = '1';   //new item default
            $data['from'] = WishListManager::FROM_EXCEL;   //new item default
            
            //if key minor is defined then get it
            if ( !empty($row['minor'])) {
              $data['minor'] = $row['minor'];   
              $data['category'] = $row['category'];   
              $data['subcategory'] = $row['subcategory'];                   
            }
                     
            switch ($row['table']) {
               case 'IMNSTA' : $this->updateSession($partnumber, 'IMNSTA'); break;                 
               case 'CATER' : $this->updateSession($partnumber, 'CATER');
                            $data['major'] = '01';
                            $caterKomat = true;
                break;
               case 'KOMAT' : $this->updateSession($partnumber, 'KOMAT'); 
                            $data['major'] = '03';
                            $caterKomat = true;
                break;
            }
            //update description and price, so that's why the (updateSession() calls)
                        
            if ($caterKomat) {
                $properties = $this->getInfo($data['partnumber']);
                
                $data['partnumberdesc'] = $properties['partnumberdesc'];
                $data['price'] = $properties['price'];
            }
            
            $this->session->fromExcel = true;
                                
           $inserted = $this->insert( $data );                        
           
           if ( !$inserted ) {
                throw new \Exception($data['partnumber'].' could not be inserted into the wish list.');
           }
        }//END FOR 
        
        return $this->session->countUploaded == count( $listValid );  
    }//END insertValid() into WL
    
    /**
     * 
     * @param type $spread | spreadsheet generated 
     * @param type $cell | Cell will be hightlighter
     * @param type $bold | True or False if it will be BOLD
     * @param type $color | color
     */
    private function highLighter( $spread, $cell, $bold=true, $color="" ) 
    {        
        $styleOptions = ['font'=>['bold'=> $bold, 'color'=>['rgb'=> $color]]];        
        
        $spread->getActiveSheet()->getStyle($cell)->applyFromArray( $styleOptions );         
    }     
    
    /**
     * 
     * @param Spreadsheet $sheet
     * @param array() $options
     */
    private function createSheetHeaders($sheet, $options )
    {
        foreach ($options as $key => $value)     {
            $sheet->getActiveSheet()->setCellValue($value['cell'], $value['desc']);
            if ($value['dimension'] != -1 ) {
                $sheet->getActiveSheet()->getColumnDimensionByColumn( $key )->setWidth(26);                 
            }
        }        
    }
        
    
    /**
     * This method inserted update ErrorsInXls 
     * @param array() $inconsistence
     */
    private function updateErrorsInXls( $inconsistence )
    {   
        $row = 2;
        $inputFileType = 'Xls';        
        
        //creating a new Spreadsheet()
        $spreadsheet = new Spreadsheet();
        $writer = IOFactory::createWriter( $spreadsheet,  $inputFileType );          
                
        $spreadsheet->setActiveSheetIndex(0);
        $spreadsheet->getActiveSheet()->setTitle('INCONS_'.date('Y-m-d'));
//        $styleOptions = ['font'=>['bold'=> true, 'color'=>['rgb'=>'']]];
        $styleError = ['font'=>['bold'=> true, 'color'=>['rgb'=>'b21703']]];
        
        //headings 
        $this->highLighter($spreadsheet, 'A1');
        $this->highLighter($spreadsheet, 'B1');
        $this->highLighter($spreadsheet, 'C1');
                       
        //writing headers   
        //create a Method to Create headers
        $cellsOptions = [
                   '1'=>['cell'=>'A1', 'desc' =>'COD', 'dimension'=>-1],
                   '2'=>['cell'=>'B1', 'desc' =>'PART NUMBER', 'dimension'=>26],
                   '3'=>['cell'=>'C1', 'desc' =>'ERRORS', 'dimension'=>26],
            ];
        $this->createSheetHeaders( $spreadsheet, $cellsOptions);
        
        foreach ( $inconsistence as $key => $value) {                   
            $spreadsheet->getActiveSheet()->setCellValue('A' . $row, $value['code']);
            $spreadsheet->getActiveSheet()->setCellValue('B' . $row, $value['partnumber']);
            $spreadsheet->getActiveSheet()->setCellValue('C' . $row, $value['error']);        
            $spreadsheet->getActiveSheet()->getStyle( 'C' . $row )->applyFromArray( $styleError );            
            $row++;
        }
        
        try {
            // $urlInc = './data/upload/wishlist_inc.xls';
            $urlInc = 'public/data/wishlist_inc.xls';
            $writer->save( $urlInc );
            $this->session->inconsistency = true;
        } catch (Zend_Exception $error ) {
            echo "Caught exception: trying to saving the wishlist inconsistencies". get_class($error)."\n";
            echo "Message: ". $error->getMessage()."\n";            
        }
        
        
    }//END METHOD updateErrorsInXls
    
    /**
     * Auxiliar method. 
     * -invoke from readExcelFile()
     * @param array() $sheetData
     * @return boolean
     */
    private function validEXCELHeader( $sheetData ) 
    {
        return $sheetData[1]['A'] == 'COD' &&  $sheetData[1]['B'] == 'PART NUMBER' && $sheetData[1]['C'] == 'MINOR';
    }

    /**
     * 
     * @param type $sheetData
     * @return type
     */
    private function removeHeader( &$sheetData )
    {
        unset( $sheetData[1] );
        return $sheetData;
    }
    
     /* 
     * @param string $inputFileName | route of the file XLS (excel file) with the WL
     * @return array() | it returns an array with the excel file as array
     */
    private function readExcelFile( $inputFileName ) 
    {   //reading file      
        $inputFileType = 'Xls';        
        $reader = IOFactory::createReader( $inputFileType );  
        $reader->setReadDataOnly( true );        
        $spreadsheet = $reader->load($inputFileName);
        $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);
        
        // validating EXCEL FILE
        $validHeader = $this->validEXCELHeader( $sheetData );
        
        if ( !$validHeader ) {
            throw new \Exception('The excel file header is not valid. Check the documentation about it.');
        }        
        $sheetDataFilter = $this->removeHeader( $sheetData );
        
        return $sheetDataFilter;
    }
    
} //END: LostsaleController

