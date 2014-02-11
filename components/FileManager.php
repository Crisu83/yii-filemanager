<?php
/**
 * FileManager class file.
 * @author Christoffer Niska <christoffer.niska@gmail.com>
 * @copyright Copyright &copy; Christoffer Niska 2013-
 * @license http://www.opensource.org/licenses/bsd-license.php New BSD License
 * @package crisu83.yii-filemanager.components
 */

/**
 * Application component for managing files.
 *
 * Methods accessible through the 'ComponentBehavior' class:
 * @method createPathAlias($alias, $path)
 * @method import($alias)
 * @method string publishAssets($path, $forceCopy = false)
 * @method void registerCssFile($url, $media = '')
 * @method void registerScriptFile($url, $position = null)
 * @method string resolveScriptVersion($filename, $minified = false)
 * @method CClientScript getClientScript()
 * @method void registerDependencies($dependencies)
 * @method string resolveDependencyPath($name)
 */
class FileManager extends CApplicationComponent
{
    /**
     * @var string name of the files directory.
     */
    public $fileDir = 'files';

    /**
     * @var string the base path (defaults to 'webroot').
     */
    public $basePath = 'webroot';

    /**
     * @var string the base url. If omitted the base url for the request will be used.
     */
    public $baseUrl;

    /**
     * @var string the name of the file model class.
     */
    public $modelClass = 'File';

    /**
     * @var string path to the yii-extension library.
     */
    public $yiiExtensionAlias = 'vendor.crisu83.yii-extension';

    /**
     * Initializes the component.
     */
    public function init()
    {
        parent::init();
        $this->attachBehavior('ext', new ComponentBehavior);
        $this->createPathAlias('fileManager', dirname(__DIR__));
    }

    /**
     * Creates a file model.
     * @param string $scenario the scenario name.
     * @return File the file model.
     * @throws CException if the file model does not extend the "File" class.
     */
    public function createModel($scenario = 'insert')
    {
        /* @var File $model */
        $model = new $this->modelClass($scenario);
        if (!$model instanceof File) {
            throw new CException(sprintf('Model class "%s" must extend "File".', $this->modelClass));
        }
        return $model;
    }

    /**
     * Saves the given file both in the database and on the hard disk.
     * @param FileResource $resource the file resource.
     * @param string $name the new name for the file.
     * @param string $path the path relative to the base path.
     * @param string $scenario name of the scenario.
     * @return File the model.
     * @throws CException if saving the image fails.
     */
    public function saveModel(FileResource $resource, $name = null, $path = null, $scenario = 'insert')
    {
        $model = $this->createModel($scenario);
        $model->extension = strtolower($resource->getExtension());
        $model->filename = $resource->getName();
        $model->mimeType = $resource->getMimeType();
        $model->byteSize = $resource->getSize();
        $model->createdAt = date('Y-m-d H:i:s');
        if ($name === null) {
            $filename = $model->filename;
            $name = substr($filename, 0, strrpos($filename, '.'));
        }
        $model->name = $this->normalizeFilename($name);
        if ($path !== null) {
            $model->path = trim($path, '/');
        }
        if (!$model->save()) {
            throw new CException('Failed to save the file model.');
        }
        $filePath = $this->getBasePath(true) . '/' . $model->getPath();
        if (!file_exists($filePath) && !$this->createDirectory($filePath)) {
            throw new CException('Failed to create the directory for the file.');
        }
        $filePath .= $model->resolveFilename();
        if (!$resource->saveAs($filePath)) {
            throw new CException('Failed to save the file.');
        }
        $model->hash = $model->calculateHash();
        $model->save(true, array('hash'));
        return $model;
    }

    /**
     * Returns the file with the given id.
     * @param integer $id the model id.
     * @param mixed $with related models that should be eager-loaded.
     * @return File the model.
     */
    public function loadModel($id, $with = array())
    {
        return CActiveRecord::model($this->modelClass)->with($with)->findByPk($id);
    }

    /**
     * Deletes a file with the given id.
     * @param integer $id the file id.
     * @return boolean whether the file was successfully deleted.
     * @throws CException if deleting the file fails.
     */
    public function deleteModel($id)
    {
        if (($model = $this->loadModel($id)) === null) {
            throw new CException(sprintf('Failed to locate file model with id "%d".', $id));
        }
        $filePath = $model->resolvePath();
        if (file_exists($filePath) && !unlink($filePath)) {
            throw new CException('Failed to delete the file.');
        }
        if (!$model->delete()) {
            throw new CException('Failed to delete the file model.');
        }
        return true;
    }

    /**
     * Sends the file associated with the given model to the user.
     * @param File $file the file model.
     * @param boolean $terminate whether to terminate the current application after calling this method.
     * @see CHttpRequest::sendFile
     */
    public function sendFile($file, $terminate = true)
    {
        Yii::app()->request->sendFile($file->resolveFilename(), $file->getContents(), $file->mimeType, $terminate);
    }

    /**
     * Sends the file associated with the given model to the user using x-sendfile.
     * @param File $file the file model.
     * @param array $options additional options.
     * @see CHttpRequest::xSendFile
     */
    public function xSendFile($file, $options = array())
    {
        Yii::app()->request->xSendFile($file->resolvePath(), $options);
    }

    /**
     * Returns the path to the files folder.
     * @param boolean $absolute whether to return an absolute path.
     * @return string the path.
     */
    public function getBasePath($absolute = false)
    {
        $path = array();
        if ($absolute) {
            if (($basePath = Yii::getPathOfAlias($this->basePath)) === false && is_dir($this->basePath)) {
                $basePath = realpath($this->basePath);
            }
            $path[] = $basePath;
        }
        $path[] = $this->fileDir;
        return implode('/', $path);
    }

    /**
     * Returns the url to the files folder.
     * @param boolean $absolute whether to return an absolute url.
     * @return string the url.
     */
    public function getBaseUrl($absolute = false)
    {
        $url = array();
        if ($absolute) {
            $url[] = $this->baseUrl !== null ? rtrim($this->baseUrl, '/') : Yii::app()->request->baseUrl;
        }
        $url[] = $this->fileDir;
        return rtrim(implode('/', $url), '/');
    }

    /**
     * Creates a directory.
     * @param string $path the directory path.
     * @param integer $mode the chmod mode (ignored on windows).
     * @param boolean $recursive whether to create the directory recursively.
     * @return boolean whether the directory was created or already exists.
     */
    public function createDirectory($path, $mode = 0777, $recursive = true)
    {
        return !file_exists($path) ? mkdir($path, $mode, $recursive) : true;
    }

    /**
     * Deletes a directory.
     * @param string $path the directory path.
     * @return boolean whether the directory was deleted.
     */
    public function deleteDirectory($path)
    {
        return is_dir($path) && file_exists($path) ? unlink($path) : false;
    }

    /**
     * Normalizes the given filename by removing illegal characters.
     * @param string $name the filename.
     * @return string the normalized filename.
     */
    public function normalizeFilename($name)
    {
        $name = str_replace(str_split('/\?%*:|"<>'), '', $name);
        return str_replace(' ', '-', $name); // for convenience
    }
}