<?php
/**
 * @package     BootstrapBase.Plugin
 * @subpackage  System
 *
 * @copyright   Copyright (C) 2013-2014 KnowledgeARC Ltd. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

// no direct access
defined('_JEXEC') or die();

jimport('joomla.log.log');

/**
 * Compile LESS files on-the-fly.
 * Less compiler less.php; see http://github.com/oyejorge/less.php
 */
class PlgSystemJLess extends JPlugin
{
    const LESS_FILE = '/less/template.less';
    const CSS_FILE_UNCOMPRESSED = '/css/template.css';
    const CSS_FILE_COMPRESSED = '/css/template.min.css';
    const CSS_FILE_SOURCEMAP = '/css/template.css.map';
    const CACHEKEY = 'file.metadata';

    public function __construct($subject, $config = array())
    {
        parent::__construct($subject, $config);

        $this->_cache = JFactory::getCache('jless', '');
        $this->_cache->setCaching(true);
    }

    /**
     * Compile .less files on change
     */
    function onBeforeRender()
    {
        $document = JFactory::getDocument();

        if (JFactory::getApplication()->isSite()) {
            if ($this->_getTemplate())
            {
                if (($compiler = $this->get('params')->get('compile', 'gpeasy')) == 'less.js') {
                    $this->_compileClientSide();
                } else {
                    JLoader::registerNamespace('Less', __DIR__.'/compilers/oyejorge/less.php/lib');

                    $this->_compileServerSide();
                }
            }
        }
    }

    private function _compileClientSide()
    {
        $document = JFactory::getDocument();

        $document->addStyleSheet(JURI::base().'templates/'.$this->_getTemplate().self::LESS_FILE);
        $document->addScript(JURI::base().'media/plg_system_jless/js/less.min.js');

        $this->_deleteCssFiles();
    }

    private function _compileServerSide()
    {
        try
        {
            $dest = $this->_getFilePathCssCompressed();

            $force = (bool)$this->params->get('force', false);

            if (!JFile::exists($dest) || $this->_isLessUpdated() || $force)
            {
                $options = array('compress'=>true);

                // Build source map with minified code.
                if ($this->params->get('generate_sourcemap', 1))
                {
                    $options['sourceMap']         = true;
                    $options['sourceMapWriteTo']  = $this->_getFilePathSourceMap();
                    $options['sourceMapURL']      = $this->_getUriSourceMap();
                    $options['sourceMapBasepath'] = JPATH_ROOT;
                }
                else
                {
                    JFile::delete($this->_getFilePathSourceMap());
                }


                $less = new Less_Parser($options);
                $less->parseFile($this->_getFilePathLess(), JUri::base());

                JFile::write($this->_getFilePathCssCompressed(), $less->getCss());
                $files = $less->allParsedFiles();

                // Generate uncompressed css.
                if ($this->params->get('generate_uncompressed', 0))
                {
                    $less = new Less_Parser();
                    $less->parseFile($src, '/');

                    JFile::write($this->_getFilePathCssUncompressed(), $less->getCss());
                }
                else
                {
                    JFile::delete($this->_getFilePathCssUncompressed());
                }

                // update cache.
                $metadata = array();

                foreach ($files as $file)
                {
                    $metadata[$file]['filesize'] = filesize($file);
                    $metadata[$file]['modified'] = filemtime($file);
                }

                $this->_cache->store($metadata, self::CACHEKEY);
            }
        }
        catch (Exception $e)
        {
            JFactory::getApplication()->enqueueMessage($e->getMessage(), 'error');
            return false;
        }
    }

    /**
     * Check the cache against the file system to determine whether the LESS
     * files have been updated.
     *
     * @return boolean True if the LESS files have been updated, false
     * otherwise.
     */
    private function _isLessUpdated()
    {
        $changed = false;

        // if there is no cached item, recompile.
        if (!is_array($files = $this->_cache->get(self::CACHEKEY)))
        {
            $changed = true;
        }

        // if the source map still exists but shouldn't be created, just recompile.
        $sourceMap = $this->_getFilePathSourceMap();
        if (JFile::exists($sourceMap) && !$this->params->get('generate_sourcemap', false)) {
            $changed = true;
        }

        // if the source map doesn't exist but should, just recompile.
        if (!JFile::exists($sourceMap) && $this->params->get('generate_sourcemap', false)) {
            $changed = true;
        }

        while (!$changed && ($metadata = current($files)) !== false) {
            $file = key($files);

            if (file_exists($file))
            {
                $sizeChanged = filesize($file) != JArrayHelper::getValue($metadata, 'filesize');
                $modifiedChanged = filemtime($file) != JArrayHelper::getValue($metadata, 'modified');

                if ($sizeChanged || $modifiedChanged)
                {
                    $changed = true;
                }
            }
            else
            {
                $changed = true;
            }

            next($files);
        }

        return $changed;
    }

    private function _deleteCssFiles()
    {
        $array = array();
        $array[] = $this->_getCssUncompressed();
        $array[] = $this->_getCssCompressed();

        foreach ($array as $value) {
            if (JFile::exists($value)) {
                JFile::delete($value);
            }
        }
    }

    private function _getFilePathCssUncompressed()
    {
        $path = JPath::clean(JPATH_ROOT.'/templates/'.$this->_getTemplate());
        return $path.self::CSS_FILE_UNCOMPRESSED;
    }

    private function _getFilePathCssCompressed()
    {
        $path = JPath::clean(JPATH_ROOT.'/templates/'.$this->_getTemplate());
        return $path.self::CSS_FILE_COMPRESSED;
    }

    private function _getFilePathSourceMap()
    {
        $path = JPath::clean(JPATH_ROOT.'/templates/'.$this->_getTemplate());
        return $path.self::CSS_FILE_SOURCEMAP;
    }

    private function _getUriSourceMap()
    {
        $path = JURI::base().'templates/'.$this->_getTemplate();
        return $path.self::CSS_FILE_SOURCEMAP;
    }

    private function _getFilePathLess()
    {
        $path = JPath::clean(JPATH_ROOT.'/templates/'.$this->_getTemplate());
        return $path.self::LESS_FILE;
    }

    private function _getTemplate()
    {
        $application = JFactory::getApplication();
        $templates = $this->get('params')->get('templates', array());
        $template = null;

        if (array_search($application->getTemplate(), $templates) !== false)
        {
            $template = $application->getTemplate();
        }
        return $template;
    }

    public function onAfterRender()
    {
        // cannot set rel="stylesheet/less for client side compilation using
        // addStylesheet so need to hack a replace in the document.
        if ($this->get('params')->get('compile') == 'less.js') {
            $body = JFactory::getApplication('site')->getBody();

            $body = preg_replace('/(<link rel="stylesheet)(" href=".*.less")/', '$1/less$2', $body);

            JFactory::getApplication('site')->setBody($body);
        }
    }
}