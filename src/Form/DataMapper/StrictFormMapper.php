<?php

declare(strict_types=1);

namespace HTC\StrictFormMapper\Form\DataMapper;

use HTC\StrictFormMapper\Contract\ValueVoterInterface;
use Symfony\Component\Form\DataMapperInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Traversable;
use TypeError;
use function iterator_to_array;
use function strpos;
use function array_search;

class StrictFormMapper implements DataMapperInterface
{
    private $defaultMapper;

    /** @var iterable|ValueVoterInterface[] */
    private $voters;

    private $translator;

    public function __construct(DataMapperInterface $defaultMapper, $voters, TranslatorInterface $translator)
    {
        $this->defaultMapper = $defaultMapper;
        $this->voters = $voters;
        $this->translator = $translator;
    }

    public function mapDataToForms($data, $forms): void
    {
        $unmappedForms = [];

        foreach ($forms as $form) {
            $reader = $form->getConfig()->getOption('get_value');
            if (!$reader) {
                $unmappedForms[] = $form;
            } else {
                try {
                    $value = $reader($data);
                    $form->setData($value);
                } catch (TypeError $e) {
                    $form->setData(null);
                }
            }
        }

        $this->defaultMapper->mapDataToForms($data, $unmappedForms);
    }

    /**
     * {@inheritdoc}
     */
    public function mapFormsToData($forms, &$data): void
    {
        if (null === $data) {
            return;
        }
        $unmappedForms = [];

        foreach ($forms as $form) {
            if (!$this->writeFormValueToData($form, $data)) {
                $unmappedForms[] = $form;
            }
        }

        $this->defaultMapper->mapFormsToData($unmappedForms, $data);
    }

    /**
     * Try to write value from form to data.
     * If 'update_value' is not defined, returns false indicating that default mapper should give it a try.
     */
    private function writeFormValueToData(FormInterface $form, &$data): bool
    {
        $config = $form->getConfig();
        /** @var null|callable $reader */
        $reader = $config->getOption('get_value');
        if (!$reader) {
            return false;
        }

        $updater = $config->getOption('update_value');
        $adder = $config->getOption('add_value');
        $remover = $config->getOption('remove_value');

        $isMultiple = $config->getOption('multiple');

        try {
            $originalValues = $reader($data);
        } catch (TypeError $e) {
            $originalValues = $isMultiple ? [] : null;
        }

        try {
            $submittedValue = $form->getData();
            if ($updater) {
                $updater($submittedValue, $data);
            } else {
                $addedValues = $this->getExtraValues($originalValues, $submittedValue);
                $removedValues = $this->getExtraValues($submittedValue, $originalValues);

                foreach ($addedValues as $value) {
                    $adder($value, $data);
                }
                foreach ($removedValues as $value) {
                    $remover($value, $data);
                }
            }
        } catch (TypeError $e) {
            // Second argument is typehinted data object.
            // We are not interested if exception happens on it; it means 'factory' failed and it is parent-level error message.
            if (false === strpos($e->getMessage(), 'Argument 2 passed to')) {
                $errorMessage = $config->getOption('write_error_message');
                if ($errorMessage) {
                    $form->addError(new FormError($this->translator->trans($errorMessage), null, [], null, $e));
                }
            }
        }

        return true;
    }

    private function getExtraValues(iterable $originalValues, array $submittedValues): array
    {
        if ($originalValues instanceof Traversable) {
            $originalValues = iterator_to_array($originalValues, true);
        }

        $extraValues = [];
        foreach ($submittedValues as $key => $value) {
            $searchKey = array_search($value, $originalValues, true);

            if (false === $searchKey || $key !== $searchKey || !$this->isEqual($submittedValues[$searchKey], $value)) {
                $extraValues[$key] = $value;
            }
        }

        return $extraValues;
    }

    private function isEqual($first, $second): bool
    {
        return $first === $second;
    }
}
