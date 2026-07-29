<?php

namespace Drupal\subtitle_derivative\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Action\ConfigurableActionBase;
use Drupal\islandora\IslandoraUtils;
use Drupal\islandora\MediaSource\MediaSourceService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\token\TokenInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\file\FileRepository;
use Drupal\file\Entity\File;
use \Done\Subtitles\Subtitles;

/**
 * @Action(
 *   id = "subtitle_derivative",
 *   label = @Translation("Generate a Subtitle Derivative"),
 *   type = "node"
 * )
 */
class SubtitleDerivative extends ConfigurableActionBase implements ContainerFactoryPluginInterface {
    
    /**
     * Islandora utility functions.
     *
     * @var \Drupal\islandora\IslandoraUtils
     */
    protected $utils;

    /**
     * The system file config.
     *
     * @var \Drupal\Core\Config\ImmutableConfig
     */
    protected $config;
    
    /**
     * Token replacement service.
     *
     * @var \Drupal\token\TokenInterface
     */
    protected $token;

    /**
     * Entity type manager.
     *
     * @var \Drupal\Core\Entity\EntityTypeManagerInterface
     */
    protected $entity_type_manager;

    /**
     * Media source service.
     *
     * @var \Drupal\islandora\MediaSource\MediaSourceService
     */
    protected $media_source;

    /**
     * File repository.
     * 
     * @var \Drupal\file\FileRepository
     */
    protected $file_repository;

     /**
     * Constructor for the action.
     * 
     * @param array $configuration
     *   A configuration array containing information about the plugin instance.
     * @param string $plugin_id
     *   The plugin_id for the plugin instance.
     * @param mixed $plugin_definition
     *   The plugin implementation definition.
     * @param \Drupal\islandora\IslandoraUtils $utils
     *   Islandora utility functions.
     * @param \Drupal\Core\Config\ConfigFactoryInterface $config
     *   The system file config.
     * @param \Drupal\token\TokenInterface $token
     *   Token service.
     * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
     *   Entity type manager.
     * @param \Drupal\islandora\MediaSource\MediaSourceService $media_source
     *   Media source service.
     * @param \Drupal\file\FileRepository $file_repository
     *   File repository.
     */
    public function __construct(
            array $configuration, 
            $plugin_id, 
            $plugin_definition,
            IslandoraUtils $utils,
            ConfigFactoryInterface $config,
            TokenInterface $token,
            EntityTypeManagerInterface $entity_type_manager,
            MediaSourceService $media_source,
            FileRepository $file_repository
    ) {
        $this->utils = $utils;
        $this->config = $config->get('system.file');
        $this->token = $token;
        $this->entity_type_manager = $entity_type_manager;
        $this->media_source = $media_source;
        $this->file_repository = $file_repository;
        parent::__construct($configuration, $plugin_id, $plugin_definition);
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $container->get('islandora.utils'),
            $container->get('config.factory'),
            $container->get('token'),
            $container->get('entity_type.manager'),
            $container->get('islandora.media_source_service'),
            $container->get('file.repository')
        );
    }

    /**
     * {@inheritdoc}
     */
    public function defaultConfiguration() {
        return [
            'source_term_uri' => '',
            'dest_term_uri' => '',
            'dest_media_type' => '',
            'dest_mime_type' => '',
            'dest_scheme' => $this->config->get('default_scheme'),
            'dest_path' => '[date:custom:Y]-[date:custom:m]/[node:nid]_transformed.html'
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
        $schemes = $this->utils->getFilesystemSchemes();
        $scheme_options = array_combine($schemes, $schemes);
        $subtitles = new Subtitles;
        $format_options = array_column($subtitles->getFormats(), 'name', 'format');
        $form['source_term'] = [
            '#type' => 'entity_autocomplete',
            '#target_type' => 'taxonomy_term',
            '#title' => $this->t('Source term'),
            '#default_value' => $this->utils->getTermForUri($this->configuration['source_term_uri']),
            '#required' => TRUE,
            '#description' => $this->t('Term indicating the source XML media'),
        ];
        $form['dest_term'] = [
            '#type' => 'entity_autocomplete',
            '#target_type' => 'taxonomy_term',
            '#title' => $this->t('Destination term'),
            '#default_value' => $this->utils->getTermForUri($this->configuration['dest_term_uri']),
            '#required' => TRUE,
            '#description' => $this->t('Term indicating the destination media'),
        ];
        $form['dest_media_type'] = [
            '#type' => 'entity_autocomplete',
            '#target_type' => 'media_type',
            '#title' => $this->t('Destination media type'),
            '#default_value' => $this->get_media_type(),
            '#required' => TRUE,
            '#description' => $this->t('The Drupal media type for the destination media'),
        ];
        $form['dest_format'] = [
            '#type' => 'select',
            '#title' => $this->t('Destination Format'),
            '#default_value' => $this->configuration['dest_format'],
            '#options' => $format_options,
            '#required' => TRUE,
            '#description' => $this->t('New format in which to save the subtitles'),
        ];
        $form['dest_mime_type'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Destination MIME Type'),
            '#default_value' => $this->configuration['dest_mime_type'],
            '#required' => FALSE,
            '#description' => $this->t('MIME type to save derivative as (e.g. text/html, application/xml, etc...)'),
        ];
        $form['dest_scheme'] = [
            '#type' => 'select',
            '#title' => $this->t('File system for destination file'),
            '#options' => $scheme_options,
            '#default_value' => $this->configuration['dest_scheme'],
            '#required' => TRUE,
        ];
        $form['dest_path'] = [
            '#type' => 'textfield',
            '#title' => $this->t('File path for destination file'),
            '#default_value' => $this->configuration['dest_path'],
            '#required' => TRUE,
            '#description' => $this->t('Path within the upload destination where the derivative file will be stored. Includes the filename and optional extension.'),
        ];
        return $form;
    }

    /**
     * {@inheritdoc}
     */
    public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
        $source_term = $this->entity_type_manager->getStorage('taxonomy_term')->load($form_state->getValue('source_term'));
        $dest_term = $this->entity_type_manager->getStorage('taxonomy_term')->load($form_state->getValue('dest_term'));
        $this->configuration['source_term_uri'] = $this->utils->getUriForTerm($source_term);
        $this->configuration['dest_term_uri'] = $this->utils->getUriForTerm($dest_term);
        $this->configuration['dest_media_type'] = $form_state->getValue('dest_media_type');
        $this->configuration['dest_mime_type'] = $form_state->getValue('dest_mime_type');
        $this->configuration['dest_scheme'] = $form_state->getValue('dest_scheme');
        $this->configuration['dest_path'] = trim($form_state->getValue('dest_path'));    
        $this->configuration['dest_format'] = trim($form_state->getValue('dest_format'));    
    }

    /**
     * {@inheritdoc}
     */
    public function execute($entity = NULL) {
        $source_term = $this->utils->getTermForUri($this->configuration['source_term_uri']);
        $this->check_exists($source_term, "Could not locate source term with uri: " . $this->configuration['source_term_uri']);
        $source_media = $this->utils->getMediaWithTerm($entity, $source_term);
        $this->check_exists($source_media, "Could not locate source media.");
        $source_file = $this->media_source->getSourceFile($source_media);
        $this->check_exists($source_file, "Could not locate source media file.");
        $dest_term = $this->utils->getTermForUri($this->configuration['dest_term_uri']);
        $this->check_exists($dest_term, "Could not locate destination term with uri: " . $this->configuration['dest_term_uri']);
        $token_data = [
            'node' => $entity,
            'media' => $source_media,
            'term' => $dest_term,
        ];
        $dest_path = $this->configuration['dest_scheme'] . '://' . $this->token->replace($this->configuration['dest_path'], $token_data);
        $source_uri = $source_file->getFileUri();
        $source_file_contents = file_get_contents($source_uri);
	$transformed_text = '';
        try {
            $subtitles = (new Subtitles())->loadFromString($source_file_contents);
            $transformed_text = $subtitles->content($this->configuration['dest_format']);
        } catch (\Done\Subtitles\Code\Exceptions\UserException $e) {
            \Drupal::logger('subtitles_derivative')->error($e->getMessage());
        }
        if ($transformed_text) {
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, $transformed_text);
            rewind($stream);
            $this->media_source->putToNode(
                $entity,
                $this->get_media_type(),
                $dest_term,
                $stream,
                $this->configuration['dest_mime_type'],
                $dest_path
            );
            fclose($stream);
        }
    }

    /**
     * Find the plaintext_media_type by id and return it or nothing.
     *
     * @return \Drupal\Core\Entity\EntityInterface|string
     *   Return the loaded entity or nothing.
     *
     * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
     *   Thrown by getStorage() if the entity type doesn't exist.
     * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
     *   Thrown by getStorage() if the storage handler couldn't be loaded.
     */
    protected function get_media_type() {
        $entity_ids = $this->entity_type_manager->getStorage('media_type')
            ->getQuery()->condition('id', $this->configuration['dest_media_type'])->execute();

        $id = reset($entity_ids);
        if ($id !== FALSE) {
            return $this->entity_type_manager->getStorage('media_type')->load($id);
        }
        return '';
    }

    /**
     * Check if an entity exists, throw an exception if it doesn't.
     * 
     * @param object $object
     *   Any object.
     * 
     * @param string $message
     *   Message to include in the exception if the object does not exist.
     * 
     * @throws \RuntimeException
     *   Thrown with the given message if the object does not exist.
     */
    protected function check_exists(&$object, $message) {
        if (!$object) {
            throw new \RuntimeException($message, 500);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function access($object, $account = NULL, $return_as_object = FALSE) {
        $result = AccessResult::allowed();
        return $return_as_object ? $result : $result->isAllowed();
    }
}
