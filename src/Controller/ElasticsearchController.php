<?php

namespace Alnv\ProSearchIndexerContaoAdapterBundle\Controller;

use Alnv\ProSearchIndexerContaoAdapterBundle\Adapter\Elasticsearch;
use Alnv\ProSearchIndexerContaoAdapterBundle\Adapter\Options;
use Alnv\ProSearchIndexerContaoAdapterBundle\Adapter\Proxy;
use Alnv\ProSearchIndexerContaoAdapterBundle\Entity\Result;
use Alnv\ProSearchIndexerContaoAdapterBundle\Helpers\Credentials;
use Alnv\ProSearchIndexerContaoAdapterBundle\Helpers\Keyword;
use Contao\CoreBundle\Controller\AbstractController;
use Contao\Input;
use Contao\PageModel;
use Contao\ModuleModel;
use Alnv\ProSearchIndexerContaoAdapterBundle\Helpers\Stats;
use Symfony\Component\HttpFoundation\JsonResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;

/**
 *
 * @Route("/elastic", defaults={"_scope" = "frontend", "_token_check" = false})
 */
class ElasticsearchController extends AbstractController
{

    /**
     *
     * @Route("/search/results", methods={"POST", "GET"}, name="get-search-results")
     */
    public function getSearchResults(): JsonResponse
    {

        $this->container->get('contao.framework')->initialize();

        $arrJsonData = \json_decode(file_get_contents('php://input'), true);

        if (!empty($arrJsonData) && is_array($arrJsonData)) {
            Input::setPost('root', $arrJsonData['root']);
            Input::setPost('module', $arrJsonData['module']);
            Input::setPost('categories', $arrJsonData['categories']);
        }

        $arrCategories = Input::post('categories') ?: (Input::get('categories') ?? []);
        $strModuleId = Input::post('module') ?: (Input::get('module') ?? '');
        $strRootPageId = Input::post('root') ?: (Input::get('root') ?? '');
        $strQuery = Input::get('query') ?? '';

        $objKeyword = new Keyword();
        $arrKeywords = $objKeyword->setKeywords($strQuery, ['categories' => $arrCategories]);

        $objCredentials = new Credentials();
        $arrCredentials = $objCredentials->getCredentials();

        $arrResults = [
            'keywords' => $arrKeywords,
            'results' => []
        ];

        $arrElasticOptions = $this->getOptionsByModuleAndRootId($strModuleId, $strRootPageId);

        switch ($arrCredentials['type']) {
            case 'elasticsearch':
            case 'elasticsearch_cloud':

                $objElasticsearchAdapter = new Elasticsearch($arrElasticOptions);
                $objElasticsearchAdapter->connect();

                if ($objElasticsearchAdapter->getClient()) {
                    $arrResults['results'] = $objElasticsearchAdapter->search($arrKeywords);
                }

                break;
            case 'licence':

                $objElasticsearchAdapter = new Elasticsearch($arrElasticOptions);
                $objElasticsearchAdapter->connect();

                $objProxy = new Proxy($objElasticsearchAdapter->getLicense());
                $arrResults['results'] = $objProxy->search($arrKeywords, $objElasticsearchAdapter->getIndexName($strRootPageId), $arrElasticOptions);

                break;
        }

        $arrHits = $arrResults['results']['hits'];
        unset($arrResults['results']['hits']);

        foreach ($arrHits as $arrHit) {

            $objEntity = new Result();
            $objEntity->addHit($arrHit['_source']['id'], ($arrHit['highlight'] ?? []), [
                'types' => $arrHit['_source']['types'],
                'score' => $arrHit['_score'],
                'elasticOptions' => $arrElasticOptions
            ]);

            if ($arrResult = $objEntity->getResult()) {
                $arrResults['results']['hits'][] = $arrResult;
            }
        }

        $objModule = ModuleModel::findByPk($strModuleId);
        $strSearchResultsTemplate = $objModule ? ($objModule->psResultsTemplate ?? 'elasticsearch_result') : 'elasticsearch_result';

        foreach (($arrResults['results']['hits'] ?? []) as $index => $arrResult) {
            $objTemplate = new \FrontendTemplate($strSearchResultsTemplate);
            $objTemplate->setData($arrResult);
            $arrResults['results']['hits'][$index]['template'] = \Controller::replaceInsertTags($objTemplate->parse());
        }

        Stats::setKeyword($arrKeywords, count(($arrResults['results']['hits'] ?? [])));

        return new JsonResponse($arrResults);
    }

    /**
     *
     * @Route("/search/autocompletion", methods={"POST", "GET"}, name="get-search-autocompletion")
     */
    public function getAutoCompletion()
    {

        $this->container->get('contao.framework')->initialize();

        $arrJsonData = \json_decode(file_get_contents('php://input'), true);

        if (!empty($arrJsonData) && is_array($arrJsonData)) {
            Input::setPost('root', $arrJsonData['root']);
            Input::setPost('module', $arrJsonData['module']);
            Input::setPost('categories', $arrJsonData['categories']);
        }

        $arrCategories = Input::post('categories') ?? [];
        $strModuleId = Input::post('module') ?: (Input::get('module') ?? '');
        $strRootPageId = Input::post('root') ?: (Input::get('root') ?? '');
        $query = Input::get('query') ?? '';

        $objCredentials = new Credentials();
        $arrCredentials = $objCredentials->getCredentials();

        $objKeyword = new Keyword();
        $arrKeywords = $objKeyword->setKeywords($query, ['categories' => $arrCategories]);

        $arrResults = [
            'keywords' => $arrKeywords,
            'results' => []
        ];

        switch ($arrCredentials['type']) {
            case 'elasticsearch':
            case 'elasticsearch_cloud':

                $objElasticsearchAdapter = new Elasticsearch($this->getOptionsByModuleAndRootId($strModuleId, $strRootPageId));
                $objElasticsearchAdapter->connect();

                if ($objElasticsearchAdapter->getClient()) {
                    $arrResults['results'] = $objElasticsearchAdapter->autocompltion($arrKeywords);
                }

                break;
            case 'licence':

                $arrOptions = $this->getOptionsByModuleAndRootId($strModuleId, $strRootPageId);
                $objElasticsearchAdapter = new Elasticsearch($arrOptions);
                $objElasticsearchAdapter->connect();

                $objProxy = new Proxy($objElasticsearchAdapter->getLicense());
                $arrResults['results'] = $objProxy->autocompletion($arrKeywords, $objElasticsearchAdapter->getIndexName($strRootPageId), $arrOptions);

                break;
        }

        return new JsonResponse($arrResults);
    }

    protected function getOptionsByModuleAndRootId($strModuleId, $strRootPageId): array
    {

        $objModule = ModuleModel::findByPk($strModuleId);
        $objRootPage = PageModel::findByPk($strRootPageId);
        $objRootPage->loadDetails();

        $strAnalyzer = $objModule->psAnalyzer ?: $objRootPage->psAnalyzer;

        $objElasticOptions = new Options();
        $objElasticOptions->setLanguage($objRootPage->language);
        $objElasticOptions->setRootPageId($strRootPageId);
        $objElasticOptions->setPerPage($objModule->perPage);
        $objElasticOptions->setAnalyzer($strAnalyzer);
        $objElasticOptions->setFuzzy((bool)$objModule->fuzzy);
        $objElasticOptions->setMinKeywordLength((int)$objModule->minKeywordLength);
        $objElasticOptions->setDomain();

        return $objElasticOptions->getOptions();
    }
}