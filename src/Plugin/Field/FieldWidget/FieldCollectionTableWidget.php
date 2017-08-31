<?php

namespace Drupal\field_collection_table\Plugin\Field\FieldWidget;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\FieldFilteredMarkup;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;


/**
 * Plugin implementation of the 'field_collection_table' widget.
 *
 * @FieldWidget(
 *   id = "field_collection_table",
 *   label = @Translation("Table"),
 *   field_types = {
 *     "field_collection"
 *   },
 * )
 */

class FieldCollectionTableWidget extends WidgetBase {

  public function form(FieldItemListInterface $items, array &$form, FormStateInterface $form_state, $get_delta = NULL) {
    $field_name = $this->fieldDefinition->getName();
    $parents = $form['#parents'];

    // Store field information in $form_state.
    if (!static::getWidgetState($parents, $field_name, $form_state)) {
      $field_state = [
        'items_count' => count($items),
        'array_parents' => [],
      ];
      static::setWidgetState($parents, $field_name, $form_state, $field_state);
    }

    // Collect widget elements.
    $elements = [];

    // If the widget is handling multiple values (e.g Options), or if we are
    // displaying an individual element, just get a single form element and make
    // it the $delta value.
    if ($this->handlesMultipleValues() || isset($get_delta)) {
      $delta = isset($get_delta) ? $get_delta : 0;
      $element = [
        '#title' => $this->fieldDefinition->getLabel(),
        '#description' => FieldFilteredMarkup::create(\Drupal::token()->replace($this->fieldDefinition->getDescription())),
      ];
      $element = $this->formSingleElement($items, $delta, $element, $form, $form_state);

      if ($element) {
        if (isset($get_delta)) {
          // If we are processing a specific delta value for a field where the
          // field module handles multiples, set the delta in the result.
          $elements[$delta] = $element;
        }
        else {
          // For fields that handle their own processing, we cannot make
          // assumptions about how the field is structured, just merge in the
          // returned element.
          $elements = $element;
        }
      }
    }
    // If the widget does not handle multiple values itself, (and we are not
    // displaying an individual element), process the multiple value form.
    else {
      $elements = $this->formMultipleElements($items, $form, $form_state);
    }

    // Populate the 'array_parents' information in $form_state->get('field')
    // after the form is built, so that we catch changes in the form structure
    // performed in alter() hooks.
//    $elements['#after_build'][] = [get_class($this), 'afterBuild'];
//    $elements['#field_name'] = $field_name;
    $elements['#field_parents'] = $parents;
    // Enforce the structure of submitted values.
    $elements['#parents'] = array_merge($parents, [$field_name]);
    // Most widgets need their internal structure preserved in submitted values.
//    $elements += ['#tree' => TRUE];

    return [
      '#type' => 'table',
      '#tree' => TRUE,
      '#prefix' => '<div id="bicz-pliz">',
      '#suffix' => '</div>',
      '#cardinality' => -1,
      // Assign a different parent, to keep the main id for the widget itself.
      '#parents' => array_merge($parents, [$field_name . '_wrapper']),
      '#field_parents' => $parents,
      '#attributes' => [
        'class' => [
          'field--type-' . Html::getClass($this->fieldDefinition->getType()),
          'field--name-' . Html::getClass($field_name),
          'field--widget-' . Html::getClass($this->getPluginId()),
        ],
      ],
      '#header' => ['1', '2', '3'],
      '#attached' => [
        'library' => [
          'core/jquery.form',
          'core/drupal.ajax',
        ],
      ]
    ] + $elements;
  }

  /**
   * Special handling to create form elements for multiple values.
   *
   * Handles generic features for multiple fields:
   * - number of widgets
   * - AHAH-'add more' button
   * - table display and drag-n-drop value reordering
   */
  protected function formMultipleElements(FieldItemListInterface $items, array &$form, FormStateInterface $form_state) {
    $field_name = $this->fieldDefinition->getName();
    $cardinality = $this->fieldDefinition->getFieldStorageDefinition()->getCardinality();
    $parents = $form['#parents'];

    // Determine the number of widgets to display.
    switch ($cardinality) {
      case FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED:
        $field_state = static::getWidgetState($parents, $field_name, $form_state);
        $max = $field_state['items_count'];
        $is_multiple = TRUE;
        break;

      default:
        $max = $cardinality - 1;
        $is_multiple = ($cardinality > 1);
        break;
    }

    $title = $this->fieldDefinition->getLabel();
    $description = FieldFilteredMarkup::create(\Drupal::token()->replace($this->fieldDefinition->getDescription()));

    $elements = [];

    for ($delta = 0; $delta <= $max; $delta++) {
      // Add a new empty item if it doesn't exist yet at this delta.
      if (!isset($items[$delta])) {
        $items->appendItem();
      }

      // For multiple fields, title and description are handled by the wrapping
      // table.
      if ($is_multiple) {
        $element = [
          '#title' => $this->t('@title (value @number)', ['@title' => $title, '@number' => $delta + 1]),
          '#title_display' => 'invisible',
          '#description' => '',
        ];
      }
      else {
        $element = [
          '#title' => $title,
          '#title_display' => 'before',
          '#description' => $description,
        ];
      }

      $element = $this->formSingleElement($items, $delta, $element, $form, $form_state);

      if ($element) {
        // Input field for the delta (drag-n-drop reordering).
        if ($is_multiple) {
          // We name the element '_weight' to avoid clashing with elements
          // defined by widget.
          $element['_weight'] = [
            '#type' => 'weight',
            '#title' => $this->t('Weight for row @number', ['@number' => $delta + 1]),
            '#title_display' => 'invisible',
            // Note: this 'delta' is the FAPI #type 'weight' element's property.
            '#delta' => $max,
            '#default_value' => $items[$delta]->_weight ?: $delta,
            '#weight' => 100,
          ];
        }

        $elements[$delta] = ['data' => $element];
      }
    }

    if ($elements) {
//      $elements += [
//        '#theme' => 'field_multiple_value_form',
//        '#field_name' => $field_name,
//        '#cardinality' => $cardinality,
//        '#cardinality_multiple' => $this->fieldDefinition->getFieldStorageDefinition()->isMultiple(),
//        '#required' => $this->fieldDefinition->isRequired(),
//        '#title' => $title,
//        '#description' => $description,
//        '#max_delta' => $max,
//      ];

      // Add 'add more' button, if not working with a programmed form.
      if ($cardinality == FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED && !$form_state->isProgrammed()) {
        $id_prefix = implode('-', array_merge($parents, [$field_name]));
        $wrapper_id = Html::getUniqueId($id_prefix . '-add-more-wrapper');
//        $elements['#prefix'] = '<div id="' . $wrapper_id . '">';
//        $elements['#suffix'] = '</div>';

        $elements['add_more']['data'][] = [
          '#type' => 'submit',
          '#name' => strtr($id_prefix, '-', '_') . '_add_more',
          '#value' => t('Add another item'),
          '#attributes' => ['class' => ['field-add-more-submit']],
          '#limit_validation_errors' => [array_merge($parents, [$field_name])],
          '#submit' => ['::addMoreSubmit'],
          '#ajax' => [
            'callback' => '::addMoreAjax',
            'wrapper' => 'bicz-pliz',
            'effect' => 'fade',
          ],
        ];
      }
    }

    return $elements;
  }


  /**
   * Generates the form element for a single copy of the widget.
   */
  protected function formSingleElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element += [
      '#field_parents' => $form['#parents'],
      // Only the first widget should be required.
      '#required' => $delta == 0 && $this->fieldDefinition->isRequired(),
      '#delta' => $delta,
      '#weight' => $delta,
    ];

    $element = $this->formElement($items, $delta, $element, $form, $form_state);

    if ($element) {
      // Allow modules to alter the field widget form element.
      $context = [
        'form' => $form,
        'widget' => $this,
        'items' => $items,
        'delta' => $delta,
        'default' => $this->isDefaultValueWidget($form_state),
      ];
      \Drupal::moduleHandler()->alter(['field_widget_form', 'field_widget_' . $this->getPluginId() . '_form'], $element, $form_state, $context);
    }

    return $element;
  }

  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    // TODO: Detect recursion
    $field_name = $this->fieldDefinition->getName();

    // Nest the field collection item entity form in a dedicated parent space,
    // by appending [field_name, delta] to the current parent space.
    // That way the form values of the field collection item are separated.
    $parents = array_merge($element['#field_parents'], [$field_name, $delta]);

    $element += [
//      '#element_validate' => [[static::class, 'validate']],
      '#parents' => $parents,
      '#field_name' => $field_name,
    ];

    $field_state = static::getWidgetState($element['#field_parents'], $field_name, $form_state);

    if (isset($field_state['entity'][$delta])) {
      $field_collection_item = $field_state['entity'][$delta];
    }
    else {
      $field_collection_item = $items[$delta]->getFieldCollectionItem(TRUE);
      // Put our entity in the form state, so FAPI callbacks can access it.
      $field_state['entity'][$delta] = $field_collection_item;
    }

    static::setWidgetState($element['#field_parents'], $field_name, $form_state, $field_state);

    $display = entity_get_form_display('field_collection_item', $field_name, 'default');
    $display->buildForm($field_collection_item, $element, $form_state);

    $header = [];
    $row = [];
    foreach (Element::children($element) as $item) {
      // Reduce the size so it fits the screen...
      $element[$item]['widget'][0]['value']['#size'] = 0;
      $weight = $element[$item]['#weight'];
      $row[$weight] = ['data' => $element[$item]];
    }

    ksort($row);

    if (empty($element['#required'])) {
//      $element['#after_build'][] = [static::class, 'delayRequiredValidation'];

      // Stop HTML5 form validation so our validation code can run instead.
      $form['#attributes']['novalidate'] = 'novalidate';
    }

    $is_first = TRUE;

    // Put the remove button on unlimited cardinality field collection fields.
    $cardinality = $this->fieldDefinition->getFieldStorageDefinition()->getCardinality();
    if ($cardinality == FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED) {
      if ($is_first) {
        $id_prefix = implode('-', array_merge($parents, [$field_name]));
//        $wrapper_id = Html::getUniqueId($id_prefix . '-add-more-wrapper');
        //        $elements['#prefix'] = '<div id="' . $wrapper_id . '">';
        //        $elements['#suffix'] = '</div>';

        $action = [
          '#type' => 'actions',
          '#cardinality' => $cardinality,
          'add_button' => [
            '#type' => 'submit',
            '#name' => strtr($id_prefix, '-', '_') . '_add_more',
            '#value' => t('Add another item'),
            '#attributes' => ['class' => ['field-add-more-submit']],
            '#limit_validation_errors' => [array_merge($parents, [$field_name])],
            '#submit' => ['Drupal\field_collection_table\Plugin\Field\FieldWidget\FieldCollectionTableWidget::addMoreSubmit'],
            '#ajax' => [
              'callback' => 'Drupal\field_collection_table\Plugin\Field\FieldWidget\FieldCollectionTableWidget::addMoreAjax',
              'wrapper' => 'bicz-pliz',
              'effect' => 'fade',
            ],
          ],
        ];
      }
      else {
        $options = ['query' => ['element_parents' => implode('/', $element['#parents'])]];

        $action = [
          '#type' => 'actions',
          'remove_button' => [
            '#delta' => $delta,
            '#name' => implode('_', $parents) . '_remove_button',
            '#type' => 'submit',
            '#value' => t('Remove'),
            '#validate' => [],
            '#submit' => [[static::class, 'removeSubmit']],
            '#limit_validation_errors' => [],
            '#ajax' => [
              'callback' => [$this, 'ajaxRemove'],
              'options' => $options,
              'effect' => 'fade',
              'wrapper' => $form['#wrapper_id'],
            ],
            '#weight' => 1000,
          ],
        ];
      }
      $row[10000]['data'] = $action;
    }

    return $row;
  }

  /**
   * Submission handler for the "Add another item" button.
   */
  public static function addMoreSubmit(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();

    // Go one level up in the form, to the widgets container.
    $element = NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -5));
    $field_name = 'field_skladniki';
//    $field_name = $element['#field_name'];
//    $parents = $element['#field_parents'];
    $parents = [];

    // Increment the items count.
    $field_state = static::getWidgetState($parents, $field_name, $form_state);
    $field_state['items_count']++;
    static::setWidgetState($parents, $field_name, $form_state, $field_state);

    $form_state->setRebuild();
  }

  /**
   * Ajax callback for the "Add another item" button.
   *
   * This returns the new page content to replace the page content made obsolete
   * by the form submission.
   */
  public static function addMoreAjax(array $form, FormStateInterface $form_state) {
    $button = $form_state->getTriggeringElement();

    // Go one level up in the form, to the widgets container.
    $element = NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -5));

    // Ensure the widget allows adding additional items.
    if ($element['#cardinality'] != FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED) {
      return;
    }

    // Add a DIV around the delta receiving the Ajax effect.
    $delta = $element['#max_delta'];
    $element[$delta]['#prefix'] = '<div class="ajax-new-content">' . (isset($element[$delta]['#prefix']) ? $element[$delta]['#prefix'] : '');
    $element[$delta]['#suffix'] = (isset($element[$delta]['#suffix']) ? $element[$delta]['#suffix'] : '') . '</div>';

    return $element;
  }

  /**
   * Ajax callback to remove a field collection from a multi-valued field.
   *
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   An AjaxResponse object.
   *
   * @see self::removeSubmit()
   */
  function ajaxRemove(array $form, FormStateInterface &$form_state) {
    // At this point, $this->removeSubmit() removed the element so we just need
    // to return the parent element.
    $button = $form_state->getTriggeringElement();
    return NestedArray::getValue($form, array_slice($button['#array_parents'], 0, -3));
  }

}