import type { Course, HomepageEbookItem, HomepageProductItem } from '../types/lms';

export interface DigitalCarouselEntry {
  id: string;
  type: 'ebook' | 'product';
  title: string;
  titleBn: string;
  category: string;
  summary: string;
  summaryBn: string;
  price: number;
  originalPrice?: number;
  isFree: boolean;
  badge: string;
  imageUrl?: string;
  colorTheme: HomepageEbookItem['colorTheme'];
  pages?: number;
}

export function filterCoursesByCategory(courses: Course[], category: string): Course[] {
  const normalized = category.trim().toLowerCase();
  if (!normalized || normalized === 'all') {
    return courses;
  }

  return courses.filter(course => {
    const courseCat = (course.category || '').toLowerCase();
    const courseTitle = (course.title || '').toLowerCase();
    if (courseCat === normalized || courseCat.includes(normalized)) {
      return true;
    }
    if (normalized === 'phonetics' && (courseCat.includes('spoken') || courseTitle.includes('phonetics'))) {
      return true;
    }
    return false;
  });
}

export function getCourseCategoryCounts(courses: Course[]): Record<string, number> {
  const counts: Record<string, number> = { all: courses.length };
  for (const course of courses) {
    const cat = (course.category || '').trim();
    if (cat) {
      counts[cat] = (counts[cat] || 0) + 1;
    }
  }
  return counts;
}

export function calculateCourseSavings(price: number, originalPrice?: number): number {
  if (!originalPrice || originalPrice <= price) {
    return 0;
  }
  return Math.max(0, Math.round(originalPrice - price));
}

export function resolveNextSlideIndex(current: number, total: number, delta: number): number {
  if (total <= 0) return 0;
  return (((current + delta) % total) + total) % total;
}

export function buildDigitalCarouselItems(
  ebooks: HomepageEbookItem[] = [],
  products: HomepageProductItem[] = []
): DigitalCarouselEntry[] {
  const productEntries: DigitalCarouselEntry[] = products
    .filter(p => p.isActive !== false)
    .map(p => ({
      id: p.id,
      type: 'product',
      title: p.title,
      titleBn: p.titleBn || p.title,
      category: p.category || 'Study Kit',
      summary: p.description,
      summaryBn: p.descriptionBn || p.description,
      price: Math.max(0, p.price || 0),
      originalPrice: p.originalPrice,
      isFree: (p.price || 0) <= 0,
      badge: p.inStock ? 'IN STOCK' : 'STUDY KIT',
      imageUrl: p.imageUrl,
      colorTheme: 'indigo',
    }));

  const ebookEntries: DigitalCarouselEntry[] = ebooks
    .filter(eb => eb.isActive !== false)
    .map(eb => ({
      id: eb.id,
      type: 'ebook',
      title: eb.title,
      titleBn: eb.titleBn || eb.title,
      category: eb.category || 'E-Book PDF',
      summary: eb.summary,
      summaryBn: eb.summaryBn || eb.summary,
      price: eb.isFree ? 0 : Math.max(0, eb.price || 0),
      originalPrice: eb.originalPrice,
      isFree: Boolean(eb.isFree || (eb.price || 0) <= 0),
      badge: eb.isFree ? 'FREE PDF' : 'PREMIUM PDF',
      imageUrl: eb.coverImageUrl,
      colorTheme: eb.colorTheme || 'sky',
      pages: eb.pages,
    }));

  return [...productEntries, ...ebookEntries];
}
