<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Weidner\Goutte\GoutteFacade as Goutte;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Company;

class WebScraperController extends Controller
{
    //Array responsible for holding all the company links
    protected $allCompanieslinks = [];

    //Array used to hold the allowed SBI Codes
    protected $SoftwareSBICodes = [];

    //Used in @getSBIcodes, used to assign the current sbi code in a loop
    protected $currentNumber = 0;

    //All kind of legal forms assigned with an ID corresponding to the that of the ID in the database
    protected $legal_forms = [
        'B.V' => 1,
        'B.V.' => 1,
        'V.O.F' => 2,
        'V.O.F.' => 2,
        'C.V' => 3,
        'C.V.' => 3,
    ];

    //Array responsible for holding the scraped data from a company
    protected $scrapedCompanyData = array();

    protected $legal_form;

    // Used for end response to give feedback to user
    protected $totalCompaniesAddedToDB = 0;
    protected $totalCompaniesUpdatedInDb = 0;

    /*
     * Function to start scraping and then to return a response with info
     * @return Illuminate\Http\Response
    */
    public function startScraper()
    {
        //Get all the links from the companies on the first page
        $this->getCompanyLink();

        //Response containing data for the front-end
        return response([
            'Message' => 'Successvol gescraped',
            'totalCompaniesScanned' => count($this->allCompanieslinks),
            'totalCompaniesAddedToDb' => $this->totalCompaniesAddedToDB,
            'totalCompaniesUpdatedInDb' => $this->totalCompaniesUpdatedInDb
        ]);
    }

    /*
     * Function used to get the settings for the webscraper
     * @return Illuminate\Http\Response
    */
    public function getScraperSettings()
    {
        // $webscraperIDS = DB::table('webscraper_settings')->pluck('id')->toArray();
        // Get current settings from database
        $settings = DB::table('webscraper_settings')->where('id', '=', 1)->first();

        // Return response for the front-end
        // Contains the SBI Codes and the ID of the webscraper used
        return response([
            //'webscraperIDS'=>array_values($webscraperIDS),
            'webscraperID' => $settings->id,
            'sbi_codes' => $settings->sbi_codes
        ]);
    }

    /*
     * Function used to update the Webscraper settings
     * @param Illuminate\Http\Request $request
     * @return Illuminate\Support\Facade\Validator
     * or
     * @return Illuminate\Http\Response
     */
    public function updateScraperSettings(Request $request)
    {
        // Validate the input
        $validator = validator::make($request->all(), [
            'ID' => 'required|int',
            'sbi_codes' => 'required|string'
        ]);

        // If the validation fails, return errors in Json format
        if ($validator->fails()) {
            return $validator->errors()->toJson();
        } else {
            //Update the webscraper settings in the database
            DB::table('webscraper_settings')->where('id', $request->ID)->update(['sbi_codes' => $request->sbi_codes]);
            return response('Succesvol geüpdated!', 201);
        }
    }

    /*
     * Function used to filter through the companies, used to check if a company has an allowed SBI code
     * @return $this->getCompanyData
     * or
     * @return void
    */
    public function getCompanyLink()
    {
        //Get a copy of the page
        $crawler = Goutte::request('GET', 'https://www.faillissementsdossier.nl/nl/nieuwe-faillissementen.aspx');

        //Filter for the name of the company
        $crawler->filter('.dossiertitle')->each(function ($node) {
            array_push($this->allCompanieslinks, ($node->children())->attr('href'));
        });
        return $this->filterCompanies();
    }

    /*
     * Function used to store a new company in the Database
     * @param int $sbi_code_id
     * @param int $statusID
     * @return Illuminate\Http\Response
     */
    public function storeCompanyData($sbi_code_id, $statusID)
    {
        $this->totalCompaniesAddedToDB += 1;
        echo nl2br("\n Inserting current company into database");
        $scrapedCompanyData = $this->scrapedCompanyData;

        //Make new instance of Company
        $company = new Company();

        //Asign values to $company
        $company->name = $scrapedCompanyData['Naam:'];
        $company->city = $scrapedCompanyData['Plaats:'];
        $company->region = $scrapedCompanyData['Provincie:'];
        $company->kvk_number = $scrapedCompanyData['KvK nummer:'];
        $company->company_priority = 0;
        $company->legal_form_id = $this->legal_form;
        $company->status_id = $statusID;
        $company->sbi_code_id = $sbi_code_id;
        $company->progression_id = 1;
        $company->created_at = \Carbon\Carbon::now();
        $company->updated_at = \Carbon\Carbon::now();


        //If there is a value for establishment date, then assign that value to $company
        if (array_key_exists('Datum oprichting:', $scrapedCompanyData)) {
            $company->establishment_date = $scrapedCompanyData['Datum oprichting:'];
        }

        $company->save();

        return response('Bedrijf successvol toegevoegd aan the database');
    }

    /*
     * Function used to update the data of a company in the database
     * @param int @statusID
     * @return void
     */
    public function updateCompanyData($statusID)
    {
        $this->totalCompaniesUpdatedInDb += 1;
        echo nl2br("\n Updating current company...");

        $scrapedCompanyData = $this->scrapedCompanyData;

        //Update the status_id and updated_at of the company in the company
        DB::table('companies')->where('kvk_number', '=', $scrapedCompanyData['KvK nummer:'])
            ->update([
                'status_id' => $statusID,
                "updated_at" => \Carbon\Carbon::now()
            ]);

        echo nl2br("\n Successfully updated current company!");
    }
}