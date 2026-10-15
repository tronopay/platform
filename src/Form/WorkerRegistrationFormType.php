<?php

namespace App\Form;

use App\Entity\Worker;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Simple worker registration: a Tron wallet address (validated by
 * #[ValidTronAddress] on the entity) plus a password.
 *
 * @see ui-spec.md §10
 */
final class WorkerRegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('tronAddress', TextType::class, [
                'label' => 'Tron wallet address',
                'attr' => [
                    'placeholder' => 'T...',
                    'autocomplete' => 'username',
                    'autocapitalize' => 'none',
                    'spellcheck' => 'false',
                ],
                'constraints' => [
                    new NotBlank(message: 'Please enter your Tron wallet address.'),
                ],
            ])
            ->add('plainPassword', PasswordType::class, [
                'mapped' => false,
                'label' => 'Password',
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => [
                    new NotBlank(message: 'Please enter a password.'),
                    new Length(min: 8, minMessage: 'The password must be at least {{ limit }} characters long.'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Worker::class,
        ]);
    }
}