<?php

namespace Drupal\field_collection_table\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\field\Entity\FieldConfig;
/**
 * Plugin implementation of the 'field_collection_table_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "field_collection_table_formatter",
 *   label = @Translation("Table"),
 *   field_types = {
 *     "field_collection"
 *   }
 * )
 */
class FieldCollectionTableFormatter extends FormatterBase {
  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {

    /**
     * {@inheritdoc}
     */
    public function settingsSummary() {
      $summary = [];
      $settings = $this->getSettings();

      $summary[] = t('Summary of the table settings');

      return $summary;
    }

    /**
     * Get config of field to get order of fields.
     *
     * TODO : make default configurable through settings.
     */
    $field_collection_field = $this->fieldDefinition->getName();
    $key = 'core.entity_view_display.field_collection_item.'.$field_collection_field.'.default';
    $content = \Drupal::config($key)->get('content');

    /**
     * Loop all items and get field labels and data.
     */
    foreach ($items as $delta => $item) {
      /** @var \Drupal\field_collection\Entity\FieldCollection $field_collection_item */
      if($field_collection_item = $item->getFieldCollectionItem()) {
        $row = [];
        foreach ($field_collection_item->getFieldDefinitions() as $fieldname => $field_definition) {
          if (isset($content[$fieldname]) && $field_definition instanceof FieldConfig) {
            $weight = $content[$fieldname]['weight'];
            if (!isset($header[$weight])) {
              $header[$weight] = $field_definition->getLabel();
              $content[$fieldname]['label'] = 'hidden';
              $formatters[$fieldname] = \Drupal::service('plugin.manager.field.formatter')->getInstance(array(
                'field_definition' => $field_definition,
                'view_mode' => 'default',
                'configuration' => $content[$fieldname],
              ));
            }
            $formatter = $formatters[$fieldname];
            $entities = $field_collection_item->{$fieldname};
            $formatter->prepareView(array($entities));
            $build = $formatter->view($field_collection_item->{$fieldname});

            $row[$weight] = render($build);
          }
        }
        ksort($row);
        $rows[] = $row;
      }
    }
    ksort($header);

    $table = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
    ];

    return ['#markup' => render($table)];
  }
}