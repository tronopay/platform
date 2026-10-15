<?php

namespace App\Form;

use App\Entity\TaskResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Task result submission form: mandatory message + optional multiple files.
 * Files are removed one at a time via a dedicated route and confirmation modal
 * (see CabinetController::removeFile), so this form carries no removal field.
 */
final class TaskResultFormType extends AbstractType
{
    public function __construct(
        #[Autowire(param: 'task_result_message_maxlength')]
        private readonly int $messageMaxLength,
        #[Autowire(param: 'task_result_file_max_size')]
        private readonly string $fileMaxSize,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $existingFileNames = $options['existing_file_names'];

        $builder
            ->add('message', TextareaType::class, [
                'label' => 'Result message',
                'attr' => [
                    'rows' => 5,
                    'placeholder' => 'Describe what was done...',
                    'maxlength' => $this->messageMaxLength,
                ],
                'constraints' => [
                    new NotBlank(message: 'The result message is required.'),
                    new Length(max: $this->messageMaxLength, maxMessage: 'The message length must not exceed {{ limit }} characters.'),
                ],
            ])
            ->add('files', FileType::class, [
                'label' => 'Attachments (optional)',
                'multiple' => true,
                'required' => false,
                'mapped' => false,
                'attr' => ['accept' => 'image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv,.zip'],
                'constraints' => [
                    new All([
                        new File(
                            maxSize: $this->fileMaxSize,
                            mimeTypes: [
                                'image/*',
                                'application/pdf',
                                'application/msword',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'text/plain',
                                'text/csv',
                                'application/zip',
                            ],
                            mimeTypesMessage: 'This file type is not allowed. Upload images, PDF, Office documents, plain text, CSV or ZIP.',
                        ),
                    ]),
                    // One original name per result: re-uploading an already
                    // attached file must be a readable validation error, not
                    // the crash the old checkbox choices used to trigger.
                    new Callback(static function (mixed $value, ExecutionContextInterface $context) use ($existingFileNames): void {
                        $names = array_flip($existingFileNames);
                        foreach ($value ?? [] as $uploadedFile) {
                            if (!$uploadedFile instanceof UploadedFile || !$uploadedFile->isValid()) {
                                continue;
                            }
                            $name = $uploadedFile->getClientOriginalName();
                            if (\array_key_exists($name, $names)) {
                                $context->buildViolation('A file with this name is already uploaded: {{ name }}.')
                                    ->setParameter('{{ name }}', $name)
                                    ->addViolation();
                            }
                            $names[$name] = true;
                        }
                    }),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TaskResult::class,
            'existing_file_names' => [],
        ]);
        $resolver->setAllowedTypes('existing_file_names', 'array');
    }
}