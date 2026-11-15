import test from 'node:test';
import assert from 'node:assert/strict';
import {
  filterCoursesByCategory,
  getCourseCategoryCounts,
  calculateCourseSavings,
  resolveNextSlideIndex,
  buildDigitalCarouselItems,
} from './courseCatalog.ts';
import type { Course, HomepageEbookItem, HomepageProductItem } from '../types/lms.ts';

const sampleCourses: Course[] = [
  {
    id: '1',
    branchId: '1',
    title: 'IELTS Preparation Masterclass',
    titleBn: '',
    category: 'IELTS',
    subtitle: '',
    subtitleBn: '',
    description: '',
    descriptionBn: '',
    instructorName: 'Alom',
    instructorTitle: 'Lead',
    instructorAvatar: '',
    price: 8500,
    originalPrice: 12000,
    durationWeeks: 12,
    totalLessons: 24,
    totalStudents: 120,
    rating: 5,
    reviewCount: 40,
    level: 'Intermediate',
    thumbnail: '',
    featured: true,
    syllabus: [],
  },
  {
    id: '2',
    branchId: '1',
    title: 'Spoken English & Fluency Bootcamp',
    titleBn: '',
    category: 'Spoken',
    subtitle: '',
    subtitleBn: '',
    description: '',
    descriptionBn: '',
    instructorName: 'Tanvir',
    instructorTitle: 'Coach',
    instructorAvatar: '',
    price: 4500,
    originalPrice: 6500,
    durationWeeks: 8,
    totalLessons: 16,
    totalStudents: 210,
    rating: 5,
    reviewCount: 55,
    level: 'Beginner',
    thumbnail: '',
    featured: true,
    syllabus: [],
  },
];

test('filterCoursesByCategory filters case-insensitively and handles phonetics fallback', () => {
  assert.equal(filterCoursesByCategory(sampleCourses, 'all').length, 2);
  assert.equal(filterCoursesByCategory(sampleCourses, 'ielts').length, 1);
  assert.equal(filterCoursesByCategory(sampleCourses, 'Phonetics').length, 1);
  assert.equal(filterCoursesByCategory(sampleCourses, 'Writing').length, 0);
});

test('getCourseCategoryCounts computes total and category breakdown', () => {
  const counts = getCourseCategoryCounts(sampleCourses);
  assert.deepEqual(counts, { all: 2, IELTS: 1, Spoken: 1 });
});

test('calculateCourseSavings returns positive discount or zero', () => {
  assert.equal(calculateCourseSavings(8500, 12000), 3500);
  assert.equal(calculateCourseSavings(5000, 5000), 0);
  assert.equal(calculateCourseSavings(5000, 3000), 0);
});

test('resolveNextSlideIndex wraps forward and backward safely', () => {
  assert.equal(resolveNextSlideIndex(0, 3, -1), 2);
  assert.equal(resolveNextSlideIndex(2, 3, 1), 0);
  assert.equal(resolveNextSlideIndex(1, 3, 1), 2);
  assert.equal(resolveNextSlideIndex(0, 0, 1), 0);
});

test('buildDigitalCarouselItems merges active storefront products and ebooks with badges', () => {
  const ebooks: HomepageEbookItem[] = [
    {
      id: 'eb_1',
      title: 'IELTS Essay Guide',
      titleBn: 'গাইড',
      category: 'IELTS Writing',
      summary: 'Essay summary',
      summaryBn: 'সারাংশ',
      price: 0,
      isFree: true,
      pages: 48,
      colorTheme: 'sky',
      topics: ['Task 2'],
      isActive: true,
    },
    {
      id: 'eb_hidden',
      title: 'Hidden Ebook',
      titleBn: 'লুকানো',
      category: 'Grammar',
      summary: 'Hidden',
      summaryBn: 'লুকানো',
      price: 150,
      isFree: false,
      pages: 30,
      colorTheme: 'purple',
      topics: [],
      isActive: false,
    },
  ];
  const products: HomepageProductItem[] = [
    {
      id: 'prd_1',
      title: 'Printed IELTS Book Set',
      titleBn: 'বই সেট',
      category: 'Printed Books',
      description: '3 hardcover books',
      descriptionBn: '৩টি বই',
      price: 1250,
      originalPrice: 1500,
      inStock: true,
      imageUrl: '/book.jpg',
      isActive: true,
    },
  ];

  const items = buildDigitalCarouselItems(ebooks, products);
  assert.equal(items.length, 2);
  assert.equal(items[0].type, 'product');
  assert.equal(items[0].badge, 'IN STOCK');
  assert.equal(items[0].price, 1250);
  assert.equal(items[1].type, 'ebook');
  assert.equal(items[1].badge, 'FREE PDF');
  assert.equal(items[1].isFree, true);
});
