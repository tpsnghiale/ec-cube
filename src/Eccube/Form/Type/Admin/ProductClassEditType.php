<?php
/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) 2000-2015 LOCKON CO.,LTD. All Rights Reserved.
 *
 * http://www.lockon.co.jp/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */

namespace Eccube\Form\Type\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Entity\ClassCategory;
use Eccube\Entity\ProductClass;
use Eccube\Form\DataTransformer;
use Eccube\Form\Type\Master\DeliveryDurationType;
use Eccube\Form\Type\Master\SaleTypeType;
use Eccube\Form\Type\PriceType;
use Eccube\Repository\BaseInfoRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ProductClassEditType extends AbstractType
{
    /**
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @var ValidatorInterface
     */
    protected $validator;

    /**
     * @var BaseInfoRepository
     */
    protected $baseInfoRepository;

    /**
     * ProductClassEditType constructor.
     *
     * @param EntityManagerInterface $entityManager
     * @param ValidatorInterface $validator
     * @param BaseInfoRepository $baseInfoRepository
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
        BaseInfoRepository $baseInfoRepository
    ) {
        $this->entityManager = $entityManager;
        $this->validator = $validator;
        $this->baseInfoRepository = $baseInfoRepository;
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('checked', CheckboxType::class, [
                'label' => false,
                'required' => false,
                'mapped' => false,
            ])
            ->add('code', TextType::class, [
                'required' => false,
            ])
            ->add('stock', NumberType::class, [
                'required' => false,
            ])
            ->add('stock_unlimited', CheckboxType::class, [
                'required' => false,
                'label' => 'productclass.label.unlimited',
            ])
            ->add('sale_limit', NumberType::class, [
                'required' => false,
            ])
            ->add('price01', PriceType::class, [
                'required' => false,
            ])
            ->add('price02', PriceType::class, [
                'required' => false,
            ])
            ->add('tax_rate', TextType::class, [
                'required' => false,
            ])
            ->add('delivery_fee', PriceType::class, [
                'required' => false,
            ])
            ->add('sale_type', SaleTypeType::class, [
                'multiple' => false,
                'expanded' => false,
            ])
            ->add('delivery_duration', DeliveryDurationType::class, [
                'required' => false,
                'placeholder' => 'productclass.placeholder.not_specified',
            ]);

        $transformer = new DataTransformer\EntityToIdTransformer($this->entityManager, ClassCategory::class);
        $builder
            ->add($builder->create('ClassCategory1', HiddenType::class)
                ->addModelTransformer($transformer)
            )
            ->add($builder->create('ClassCategory2', HiddenType::class)
                ->addModelTransformer($transformer)
            );

        // 各行の個別税率設定.
        $this->setTaxRate($builder);

        // 各行の登録チェックボックス.
        $this->setCheckbox($builder);

        // バリデーションの設定. 各行にチェックが付いているときだけ検証する.
        $this->addValidations($builder);
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults([
            'data_class' => ProductClass::class,
        ]);
    }

    /**
     * 各行の個別税率設定の制御.
     *
     * @param FormBuilderInterface $builder
     */
    protected function setTaxRate(FormBuilderInterface $builder)
    {
        if (!$this->baseInfoRepository->get()->isOptionProductTaxRule()) {
            return;
        }
        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) {
            $data = $event->getData();
            if (!$data instanceof ProductClass) {
                return;
            }
            if ($data->getId() && $data->getTaxRule()) {
                $form = $event->getForm();
                $form['tax_rate']->setData($data->getTaxRule()->getTaxRate());
            }
        });
    }

    /**
     * 各行の登録チェックボックスの制御.
     *
     * @param FormBuilderInterface $builder
     */
    protected function setCheckbox(FormBuilderInterface $builder)
    {
        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) {
            $data = $event->getData();
            if (!$data instanceof ProductClass) {
                return;
            }
            if ($data->getId() && $data->isVisible()) {
                $form = $event->getForm();
                $form['checked']->setData(true);
            }
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
            $form = $event->getForm();
            $data = $event->getData();
            $data->setVisible($form['checked']->getData() ? true : false);
        });
    }

    protected function addValidations(FormBuilderInterface $builder)
    {
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
            $form = $event->getForm();
            $data = $form->getData();

            if (!$form['checked']->getData()) {
                // チェックがついていない場合はバリデーションしない.
                return;
            }

            // 在庫数
            $errors = $this->validator->validate($data['stock'], [
                new Assert\Regex([
                    'pattern' => "/^\d+$/u",
                    'message' => 'form.type.numeric.invalid',
                ]),
            ]);
            $this->addErrors('stock', $form, $errors);

            // 在庫数無制限
            if (empty($data['stock_unlimited']) && null === $data['stock']) {
                $form['stock_unlimited']->addError(new FormError('productclass.text.error.set_stock_quantitiy'));
            }

            // 販売制限数
            $errors = $this->validator->validate($data['sale_limit'], [
                new Assert\Length([
                    'max' => 10,
                ]),
                new Assert\GreaterThanOrEqual([
                    'value' => 1,
                ]),
                new Assert\Regex([
                    'pattern' => "/^\d+$/u",
                    'message' => 'form.type.numeric.invalid',
                ]),
            ]);
            $this->addErrors('sale_limit', $form, $errors);

            foreach ($errors as $error) {
                $form['sale_limit']->addError(new FormError($error->getMessage()));
            }

            // 販売価格
            $errors = $this->validator->validate($data['price02'], [
                new Assert\NotBlank(),
            ]);

            $this->addErrors('price02', $form, $errors);

            // 税率
            $errors = $this->validator->validate($data['tax_rate'], [
                new Assert\Range(['min' => 0, 'max' => 100]),
                new Assert\Regex([
                    'pattern' => "/^\d+(\.\d+)?$/",
                    'message' => 'form.type.float.invalid'
                ]),

            ]);
            $this->addErrors('tax_rate', $form, $errors);

            // 販売種別
            $errors = $this->validator->validate($data['sale_type'], [
                new Assert\NotBlank(),
            ]);
            $this->addErrors('sale_type', $form, $errors);
        });
    }

    protected function addErrors($key, FormInterface $form, ConstraintViolationListInterface $errors)
    {
        foreach ($errors as $error) {
            $form[$key]->addError(new FormError($error->getMessage()));
        }
    }
}